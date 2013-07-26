<?php
require_once(WWW_DIR."lib/framework/db.php");
require_once(WWW_DIR."lib/nntp.php");
require_once(WWW_DIR."lib/groups.php");
require_once(WWW_DIR."lib/backfill.php");
require_once(WWW_DIR."lib/consoletools.php");
require_once(WWW_DIR."lib/site.php");
require_once(WWW_DIR."lib/namecleaning.php");

class Binaries
{
	const BLACKLIST_FIELD_SUBJECT = 1;
	const BLACKLIST_FIELD_FROM = 2;
	const BLACKLIST_FIELD_MESSAGEID = 3;

	function Binaries()
	{
		$this->n = "\n";

		$s = new Sites();
		$site = $s->get();
		$this->compressedHeaders = ($site->compressedheaders == "1") ? true : false;
		$this->messagebuffer = (!empty($site->maxmssgs)) ? $site->maxmssgs : 20000;
		$this->NewGroupScanByDays = ($site->newgroupscanmethod == "1") ? true : false;
		$this->NewGroupMsgsToScan = (!empty($site->newgroupmsgstoscan)) ? $site->newgroupmsgstoscan : 50000;
		$this->NewGroupDaysToScan = (!empty($site->newgroupdaystoscan)) ? $site->newgroupdaystoscan : 3;
		$this->DoPartRepair = ($site->partrepair == "0" || $site->partrepair == "2") ? false : true;
		$this->DoPartRepairMsg = ($site->partrepair == "2") ? false : true;
		$this->partrepairlimit = (!empty($site->maxpartrepair)) ? $site->maxpartrepair : 15000;
		$this->hashcheck = (!empty($site->hashcheck)) ? $site->hashcheck : 0;
		$this->debug = ($site->debuginfo == "0") ? false : true;

		$this->blackList = array(); //cache of our black/white list
		$this->message = array();
		$this->blackListLoaded = false;
	}

	function updateAllGroups()
	{
		if ($this->hashcheck == 0)
		{
			echo "We have updated the way collections are created, the collection table has to be updated to use the new changes, if you want to run this now, type yes, else type no to see how to run manually.\n";
			if(trim(fgets(fopen("php://stdin","r"))) != 'yes')
				exit("If you want to run this manually, there is a script in misc/testing/DB_scripts/ called resetCollections.php\n");
			$relss = new Releases();
			$relss->resetCollections();
		}
		$n = $this->n;
		$groups = new Groups();
		$res = $groups->getActive();
		$s = new Sites();
		$counter = 1;

		if ($res)
		{
			$alltime = microtime(true);
			echo $n.'Updating: '.sizeof($res).' group(s) - Using compression? '.(($this->compressedHeaders)?'Yes':'No').$n;

			foreach($res as $groupArr)
			{
				/* In the future possibly split the collections/binaries/parts tables by group??
				 * 
				// Create new tables for the parts/binaries/collections for the group.
				$gname = $db->escapeString(str_replace('alt.binaries.', '', $groupArr['name']));
				$dbname = $db->escapeString(DB::DB_NAME);
				$res = $db->queryDirect(sprintf("SHOW TABLES FROM %s WHERE tables_in_%s like collections%s or tables_in_%s like binaries%s or tables_in_%s like parts%s", $dbname, $dbname, $gname, $dbname, $gname, $dbname, $gname));
				
				if(mysql_num_rows($res) < 3)
				{
					$db->queryDirect(sprintf("CREATE TABLE collections%s LIKE collections", $strgname));
					$db->queryDirect(sprintf("CREATE TABLE binaries%s LIKE binaries", $strgname));
					$db->queryDirect(sprintf("CREATE TABLE parts%s LIKE parts", $strgname));
				}
				else
				{
					$this->message = array();
					echo "\nStarting group ".$counter." of ".sizeof($res)."\n";
					$this->updateGroup($groupArr);
					$counter++;
				}
				*/
				$this->message = array();
				echo "\nStarting group ".$counter." of ".sizeof($res)."\n";
				$this->updateGroup($groupArr);
				$counter++;
			}

			echo 'Updating completed in '.number_format(microtime(true) - $alltime, 2).' seconds'.$n;
		}
		else
		{
			echo "No groups specified. Ensure groups are added to nZEDb's database for updating.".$n;
		}
	}

	function updateGroup($groupArr)
	{
		$db = new DB();
		$backfill = new Backfill();
		$n = $this->n;
		$this->startGroup = microtime(true);
		$nntp = new Nntp();
		$nntp->doConnect();

		echo 'Processing '.$groupArr['name'].$n;

		// Connect to server
		$data = $nntp->selectGroup($groupArr['name']);

		//if server return 411, skip group
		if (PEAR::isError($data) && $data->code == 411)
		{
			$nntp->doQuit();
			unset($nntp);
			$nntp = new Nntp;
			$nntp->doConnect();
			$data = $nntp->selectGroup($groupArr['name']);
			if (PEAR::isError($data) && $data->code == 411)
			{
				echo $n.$n."Error {$data->code}: {$data->message}".$n;
				echo "Skipping group: {$groupArr['name']}".$n;
				return;
			}
		}

		if (PEAR::isError($data))
		{
			$nntp->doQuit();
			unset($nntp);
			$nntp = new Nntp;
			$nntp->doConnect();
			$data = $nntp->selectGroup($groupArr['name']);
			if (PEAR::isError($data))
			{
				echo $n.$n."Error {$data->code}: {$data->message}".$n;
				echo "Reconnected but could not select group (bad name?): {$groupArr['name']}".$n;
				return;
			}
		}

		//Attempt to repair any missing parts before grabbing new ones
		if ($this->DoPartRepair)
		{
			echo "Part Repair Enabled... Repairing..." . $n;
			$this->partRepair($nntp, $groupArr);
		}
		elseif ($this->DoPartRepairMsg)
			echo "Part Repair Disabled... Skipping..." . $n;

		//Get first and last part numbers from newsgroup
		$last = $grouplast = $data['last'];

		// For new newsgroups - determine here how far you want to go back.
		if ($groupArr['last_record'] == 0)
		{
			if ($this->NewGroupScanByDays)
			{
				$first = $backfill->daytopost($nntp, $groupArr['name'], $this->NewGroupDaysToScan, true);
				if ($first == '')
				{
					echo "Skipping group: {$groupArr['name']}".$n;
					return;
				}
			}
			else
			{
				if ($data['first'] > ($data['last'] - $this->NewGroupMsgsToScan))
					$first = $data['first'];
				else
					$first = $data['last'] - $this->NewGroupMsgsToScan;
			}
			$first_record_postdate = $backfill->postdate($nntp, $first, false, $groupArr['name']);
			$db->query(sprintf("UPDATE groups SET first_record = %s, first_record_postdate = FROM_UNIXTIME(".$first_record_postdate.") WHERE ID = %d", $db->escapeString($first), $groupArr['ID']));
		}
		else
		{
			$first = $groupArr['last_record'] + 1;
		}

		// Generate postdates for first and last records, for those that upgraded
		if ((is_null($groupArr['first_record_postdate']) || is_null($groupArr['last_record_postdate'])) && ($groupArr['last_record'] != "0" && $groupArr['first_record'] != "0"))
			 $db->query(sprintf("UPDATE groups SET first_record_postdate = FROM_UNIXTIME(".$backfill->postdate($nntp,$groupArr['first_record'],false,$groupArr['name'])."), last_record_postdate = FROM_UNIXTIME(".$backfill->postdate($nntp,$groupArr['last_record'],false,$groupArr['name']).") WHERE ID = %d", $groupArr['ID']));

		////////NEED TO FIND BUG IN THIS
		// Deactivate empty groups
		//if (($data['last'] - $data['first']) <= 5)
			//$db->query(sprintf("UPDATE groups SET active = %s, last_updated = now() WHERE ID = %d", $db->escapeString('0'), $groupArr['ID']));

		// Calculate total number of parts
		$total = $grouplast - $first + 1;

		// If total is bigger than 0 it means we have new parts in the newsgroup
		if($total > 0)
		{
			echo "Group ".$data["group"]." has ".number_format($total)." new articles.".$n;
			echo "Server oldest: ".number_format($data['first'])." Server newest: ".number_format($data['last'])." Local newest: ".number_format($groupArr['last_record']).$n.$n;
			if ($groupArr['last_record'] == 0)
				echo "New group starting with ".(($this->NewGroupScanByDays) ? $this->NewGroupDaysToScan." days" : $this->NewGroupMsgsToScan." messages")." worth.".$n;

			$done = false;

			// Get all the parts (in portions of $this->messagebuffer to not use too much memory)
			while ($done === false)
			{
				$this->startLoop = microtime(true);

				if ($total > $this->messagebuffer)
				{
					if ($first + $this->messagebuffer > $grouplast)
						$last = $grouplast;
					else
						$last = $first + $this->messagebuffer;
				}

				echo $n."Getting ".number_format($last-$first+1)." articles (".number_format($first)." to ".number_format($last).") from ".$data["group"]." - ".number_format($grouplast - $last)." in queue".$n;
				flush();

				//get headers from newsgroup
				$lastId = $this->scan($nntp, $groupArr, $first, $last);
				if ($lastId === false)
				{
					//scan failed - skip group
					return;
				}
				$db->query(sprintf("UPDATE groups SET last_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($lastId), $groupArr['ID']));

				if ($last == $grouplast)
					$done = true;
				else
					$last = $lastId;
					$first = $last + 1;
			}

			$last_record_postdate = $backfill->postdate($nntp,$last,false,$groupArr['name']);
			$db->query(sprintf("UPDATE groups SET last_record_postdate = FROM_UNIXTIME(".$last_record_postdate."), last_updated = now() WHERE ID = %d", $groupArr['ID']));	//Set group's last postdate
			$timeGroup = number_format(microtime(true) - $this->startGroup, 2);
			echo $data["group"]." processed in $timeGroup seconds $n $n";
		}
		else
		{
			echo "No new articles for ".$data["group"]." (first ".number_format($first)." last ".number_format($last)." total ".number_format($total).") grouplast ".number_format($groupArr['last_record']).$n.$n;

		}
	}

	function scan($nntp, $groupArr, $first, $last, $type='update')
	{
		$db = new Db();
		$namecleaning = new nameCleaning();
		if ($this->debug)
		{
			$consoletools = new ConsoleTools();
			$res = $db->query("SHOW TABLES LIKE `regextesting`");
			if (!$res)
				$db->queryDirect("CREATE TABLE regextesting like collections");
		}
		$n = $this->n;
		if (!isset($nntp))
		{
			$nntp = new Nntp();
			$nntp->doConnect();
		}
		$this->startHeaders = microtime(true);
		$data = $nntp->selectGroup($groupArr['name']);
		$msgs = $nntp->getOverview($first."-".$last, true, false);
		$this->startLoop = microtime(true);
		$s = new Sites;
		$site = $s->get();
		$tmpPath = $site->tmpunrarpath."/";

		if (PEAR::isError($msgs) && ($msgs->code == 400 || $msgs->code == 411 || $msgs->code == 412 || $msgs->code == 1000))
		{
			if ($msgs->code == 400)
				echo "NNTP connection timed out. Reconnecting...$n";
			$nntp->doQuit();
			unset($nntp);
			$nntp = new Nntp;
			$nntp->doConnect();
			$data = $nntp->selectGroup($groupArr['name']);
			$msgs = $nntp->getOverview($first."-".$last, true, false);
			if (PEAR::isError($msgs))
			{
				echo $n.$n."Error {$data->code}: {$data->message}".$n;
				// If server returns error 411, skip group.
				if ($msgs->code == 411)
				{
					echo "Skipping group: {$groupArr['name']}".$n;
					return;
				}
			}
		}
		else if (PEAR::isError($msgs))
			echo $n.$n."Error {$data->code}: {$data->message}".$n;

		$rangerequested = range($first, $last);
		$msgsreceived = $msgsblacklisted = $msgsignored = $msgsnotinserted = array();
		$timeHeaders = number_format(microtime(true) - $this->startHeaders, 2);

		if ($type != 'partrepair')
		{
			if(PEAR::isError($msgs))
			{
				echo "Error {$msgs->code}: {$msgs->message}$n";
				echo "Skipping group: ${groupArr['name']}$n";
				return false;
			}
		}

		$this->startCleaning = microtime(true);
		if (is_array($msgs))
		{
			if ($this->debug)
				$colnames = $orignames = array();
			
			// Loop articles, figure out files/parts.
			foreach($msgs AS $msg)
			{
				if (!isset($msg['Number']))
					continue;
				
				if (isset($msg['Bytes']))
					$bytes = $msg['Bytes'];
				else
					$bytes = $msg[':bytes'];

				$msgsreceived[] = $msg['Number'];

				// Not a binary post most likely.. continue.
				if (!isset($msg['Subject']) || !preg_match('/(.+yEnc) \((\d+)\/(\d+)\)$/i', $msg['Subject'], $matches))
				{
					$msgsignored[] = $msg['Number'];
					continue;
				}

				// Filter subject based on black/white list.
				if ($this->isBlackListed($msg, $groupArr['name']))
				{
					$msgsblacklisted[] = $msg['Number'];
					continue;
				}
				
				// Attempt to find the file count. If it is not found, set it to 0.
				if (!preg_match('/(.+?)?(\[|\(|\s)(\d{1,4})(\/|(\s|_)of(\s|_)|\-)(\d{1,4})(\]|\)|\s|$|:)(.+)?/i', $matches[1], $filecnt))
					$filecnt[3] = $filecnt[7] = "0";
				
				// Make sure the part count is set or else continue.
				if(is_numeric($matches[2]) && is_numeric($matches[3]))
				{
					array_map('trim', $matches);
					// Inserted into the collections table as the subject.
					$subject = utf8_encode(trim($matches[1]));
					
					// Used for the grabnzb function.
					if(preg_match('/\.nzb" yEnc$/', $subject) && $site->grabnzbs != 0)
					{

						/* We already have the group if we are grabbing articles from it.
						$db->queryDirect(sprintf("INSERT IGNORE INTO `groups` (`name`, `active`, `backfill`) VALUES (%s,0,0)", $db->escapeString($groupArr['name'])));
						*/
						
						$db->queryDirect(sprintf("INSERT IGNORE INTO `nzbs` (`message_id`, `group`, `article-number`, `subject`, `filesize`, `partnumber`, `totalparts`, `postdate`, `dateadded`) values (%s, %s, %s, %s, %s, %d, %d, %d, FROM_UNIXTIME(%s), now())", $db->escapeString(substr($msg['Message-ID'],1,-1)), $db->escapeString($groupArr['name']), $db->escapeString($msg['Number']), $db->escapeString($subject), (int)$bytes, (int)$matches[2], (int)$matches[3], $db->escapeString($this->message[$subject]['Date'])));
						
						// Removing the following since it didn't work before (ID = collectionhash?) also it can't work anymore since we run this over anything else (in case we don't have a regex that matches the subject).
						//$db->queryDirect(sprintf("UPDATE `nzbs` set `dateadded` = now() WHERE ID = %s", $db->escapeString($this->message[$subject]['CollectionHash'])));
					}
					
					// Used for the collection hash and the clean name. If it returns false continue (we ignore the message - which means it did not match on a regex).
					if(!$cleanerArray = $namecleaning->collectionsCleaner($msg['Subject']))
					{
						if ($this->debug)
						{
							echo "The following article has not matched on a regex: ".$msg['Subject']."\n";
							// Insert the subject minus the file count for future making regexes.
							$name1 = $name2 = "";
							if (isset($filecnt[1]))
								$name1 = $filecnt[1];
							if (isset($filecnt[8]))
								$name2 = $filecnt[8];
							$res = $db->queryOneRow(sprintf("SELECT ID FROM regextesting WHERE name = %s", $db->escapeString($name1.$name2)));
							if(!$res)
								$db->queryDirect(sprintf("INSERT IGNORE INTO regextesting (name, subject, fromname, xref, groupID, dateadded) VALUES (%s, %s, %s, %s, %d, now())", $db->escapeString($name1.$name2), $db->escapeString($subject), $db->escapeString($msg['From']), $db->escapeString($msg['Xref']), $groupArr['ID']));
								
						}
						$msgsignored[] = $msg['Number'];
						continue;
					}
					else
					{
						// Used for the SHA1.
						$hashsubject = $cleanerArray["hash"];
						// The cleaned up subject. Inserted into the collections table as the name.
						$cleansubject = $cleanerArray["clean"];
					}
					
					// Used for looking at the difference between the original name and the clean subject.
					if ($this->debug)
					{
						if (!in_array($hashsubject, $colnames))
						{
							$colnames[] = $hashsubject;
							$orignames[] = $msg['Subject'];
						}
					}

					// Set up the info for inserting.
					if(!isset($this->message[$subject]))
					{
						$this->message[$subject] = $msg;
						$this->message[$subject]['MaxParts'] = (int)$matches[3];
						$this->message[$subject]['Date'] = strtotime($this->message[$subject]['Date']);
						$this->message[$subject]['CollectionHash'] = sha1($hashsubject.$msg['From'].$groupArr['ID'].$filecnt[7]);
						$this->message[$subject]['MaxFiles'] = (int)$filecnt[7];
						$this->message[$subject]['File'] = (int)$filecnt[3];
					}
					if((int)$matches[2] > 0)
						$this->message[$subject]['Parts'][(int)$matches[2]] = array('Message-ID' => substr($msg['Message-ID'],1,-1), 'number' => $msg['Number'], 'part' => (int)$matches[2], 'size' => $bytes);
				}
				else
				{
					// Ignore if there is no part count.
					$msgsignored[] = $msg['Number'];
					continue;
				}
			}

			// Used for looking at the difference between the original name and the clean subject.
			if ($this->debug && count($colnames) > 1 && count($orignames) > 1)
			{
				$arr = array_combine($colnames, $orignames);
				ksort($arr);
				print_r($arr);
			}
			$timeCleaning = number_format(microtime(true) - $this->startCleaning, 2);
			unset($msg, $msgs);
			$maxnum = $last;
			$rangenotreceived = array_diff($rangerequested, $msgsreceived);

			if ($type != 'partrepair')
				echo "Received ".number_format(sizeof($msgsreceived))." articles of ".(number_format($last-$first+1))." requested, ".sizeof($msgsblacklisted)." blacklisted, ".sizeof($msgsignored)." not binary at ".date('H:i:s').$n;

			if (sizeof($rangenotreceived) > 0)
			{
				switch($type)
				{
					case 'backfill':
						//don't add missing articles
						break;
					case 'partrepair':
					case 'update':
					default:
						if ($this->DoPartRepair)
							$this->addMissingParts($rangenotreceived, $groupArr['ID']);
					break;
				}
				if ($type != 'partrepair')
					echo 'Server did not return '.sizeof($rangenotreceived)." articles.".$n;
			}

			$this->startUpdate = microtime(true);
			if(isset($this->message) && count($this->message))
			{
				$maxnum = $first;
				
				// Insert binaries and parts into database. when binary already exists; only insert new parts.
				if ($insPartsStmt = $db->Prepare("INSERT IGNORE INTO parts (binaryID, number, messageID, partnumber, size) VALUES (?, ?, ?, ?, ?)"))
					$insPartsStmt->bind_param('dssss', $pBinaryID, $pNumber, $pMessageID, $pPartNumber, $pSize);
				else
					exit("Couldn't prepare parts insert statement!");

				$lastCollectionHash = $lastBinaryHash = "";
				$lastCollectionID = $lastBinaryID = -1;

				$db->setAutoCommit(false);

				foreach($this->message AS $subject => $data)
				{
					if(isset($data['Parts']) && count($data['Parts']) > 0 && $subject != '')
					{
						$collectionHash = $data['CollectionHash'];

						if ($lastCollectionHash == $collectionHash)
							$collectionID = $lastCollectionID;
						else
						{
							$lastCollectionHash = $collectionHash;
							$lastBinaryHash = "";
							$lastBinaryID = -1;

							$cres = $db->queryOneRow(sprintf("SELECT ID FROM collections WHERE collectionhash = %s", $db->escapeString($collectionHash)));
							if(!$cres)
							{
								$csql = sprintf("INSERT IGNORE INTO collections (name, subject, fromname, date, xref, groupID, totalFiles, collectionhash, dateadded) VALUES (%s, %s, %s, FROM_UNIXTIME(%s), %s, %d, %s, %s, now())", $db->escapeString($cleansubject), $db->escapeString($subject), $db->escapeString($data['From']), $db->escapeString($data['Date']), $db->escapeString($data['Xref']), $groupArr['ID'], $db->escapeString($data['MaxFiles']), $db->escapeString($collectionHash));
								$collectionID = $db->queryInsert($csql);
							}
							else
							{
								$collectionID = $cres["ID"];
								//Update the collection table with the last seen date for the collection.
								$cusql = sprintf("UPDATE collections set dateadded = now() WHERE ID = %s", $collectionID);
								$db ->queryDirect($cusql);
							}

							$lastCollectionID = $collectionID;
						}
						$binaryHash = md5($subject.$data['From'].$groupArr['ID']);

						if ($lastBinaryHash == $binaryHash)
							$binaryID = $lastBinaryID;
						else
						{
							$lastBinaryHash = $binaryHash;

							$bres = $db->queryOneRow(sprintf("SELECT ID FROM binaries WHERE binaryhash = %s", $db->escapeString($binaryHash)));
							if(!$bres)
							{
								$bsql = sprintf("INSERT IGNORE INTO binaries (binaryhash, name, collectionID, totalParts, filenumber) VALUES (%s, %s, %d, %s, %s)", $db->escapeString($binaryHash), $db->escapeString($subject), $collectionID, $db->escapeString($data['MaxParts']), $db->escapeString(round($data['File'])));
								$binaryID = $db->queryInsert($bsql);
							}
							else
								$binaryID = $bres["ID"];

							$lastBinaryID = $binaryID;
						}

						foreach($data['Parts'] AS $partdata)
						{
							$pBinaryID = $binaryID;
							$pMessageID = $partdata['Message-ID'];
							$pNumber = $partdata['number'];
							$pPartNumber = round($partdata['part']);
							$pSize = $partdata['size'];

							$maxnum = ($partdata['number'] > $maxnum) ? $partdata['number'] : $maxnum;

							if (!$insPartsStmt->execute())
								$msgsnotinserted[] = $partdata['number'];
						}
					}
				}
				if (sizeof($msgsnotinserted) > 0)
				{
					echo 'WARNING: '.sizeof($msgsnotinserted).' parts failed to insert'.$n;
					if ($this->DoPartRepair)
						$this->addMissingParts($msgsnotinserted, $groupArr['ID']);
				}
				$db->Commit();
				$db->setAutoCommit(true);
			}
			$timeUpdate = number_format(microtime(true) - $this->startUpdate, 2);
			$timeLoop = number_format(microtime(true)-$this->startLoop, 2);

			if ($type != 'partrepair')
			{
				echo $timeHeaders."s to download articles, ".$timeCleaning."s to clean articles, ".$timeUpdate."s to insert articles, ".$timeLoop."s total.".$n;
			}
			unset($this->message, $data);
			return $maxnum;
		}
		else
		{
			if ($type != 'partrepair')
			{
				echo "Error: Can't get parts from server (msgs not array)".$n;
				echo "Skipping group: ${groupArr['name']}".$n;
				return false;
			}
		}
	}

	public function partRepair($nntp, $groupArr, $groupID='', $partID='')
	{
		$n = $this->n;
		$groups = new Groups();

		// Get all parts in partrepair table.
		$db = new DB();
		if ($partID=='')
			$missingParts = $db->query(sprintf("SELECT * FROM partrepair WHERE groupID = %d AND attempts < 5 ORDER BY numberID ASC LIMIT %d", $groupArr['ID'], $this->partrepairlimit));
		else
		{
			$groupArr = $groups->getByID($groupID);
			$missingParts = array(array('numberID' => $partID, 'groupID' => $groupArr['ID']));
		}
		$partsRepaired = $partsFailed = 0;

		if (sizeof($missingParts) > 0)
		{
			if ($partID=='')
				echo "Attempting to repair ".sizeof($missingParts)." parts...".$n;

			// Loop through each part to group into ranges.
			$ranges = array();
			$lastnum = $lastpart = 0;
			foreach($missingParts as $part)
			{
				if (($lastnum+1) == $part['numberID']) {
					$ranges[$lastpart] = $part['numberID'];
				} else {
					$lastpart = $part['numberID'];
					$ranges[$lastpart] = $part['numberID'];
				}
				$lastnum = $part['numberID'];
			}

			$num_attempted = 0;
			$consoleTools = new ConsoleTools();

			// Download missing parts in ranges.
			foreach($ranges as $partfrom=>$partto)
			{
				$this->startLoop = microtime(true);

				$num_attempted += $partto - $partfrom + 1;
				if ($partID=='')
				{
					echo $n;
					$consoleTools->overWrite("Attempting repair: ".$consoleTools->percentString($num_attempted,sizeof($missingParts)).": ".$partfrom." to ".$partto);
				}
				else
					echo "Attempting repair: ".$partfrom.$n;

				// Get article from newsgroup.
				$this->scan($nntp, $groupArr, $partfrom, $partto, 'partrepair');

				// Check if the articles were added.
				$articles = implode(',', range($partfrom, $partto));
				$sql = sprintf("SELECT pr.ID, pr.numberID, p.number from partrepair pr LEFT JOIN parts p ON p.number = pr.numberID WHERE pr.groupID=%d AND pr.numberID IN (%s) ORDER BY pr.numberID ASC", $groupArr['ID'], $articles);

				$result = $db->queryDirect($sql);
				while ($r = $db->fetchAssoc($result))
				{
					if (isset($r['number']) && $r['number'] == $r['numberID'])
					{
						$partsRepaired++;

						// Article was added, delete from partrepair.
						$db->query(sprintf("DELETE FROM partrepair WHERE ID=%d", $r['ID']));
					}
					else
					{
						$partsFailed++;

						// Article was not added, increment attempts.
						$db->query(sprintf("UPDATE partrepair SET attempts=attempts+1 WHERE ID=%d", $r['ID']));
					}
				}
			}

			if ($partID=='')
				echo $n;
			echo $partsRepaired.' parts repaired.'.$n;
		}

		// Remove articles that we cant fetch after 5 attempts.
		$db->query(sprintf("DELETE FROM partrepair WHERE attempts >= 5 AND groupID = %d", $groupArr['ID']));

	}

	private function addMissingParts($numbers, $groupID)
	{
		$db = new DB();
		$insertStr = "INSERT IGNORE INTO partrepair (numberID, groupID) VALUES ";
		foreach($numbers as $number)
		{
			$insertStr .= sprintf("(%d, %d), ", $number, $groupID);
		}
		$insertStr = substr($insertStr, 0, -2);
		$insertStr .= " ON DUPLICATE KEY UPDATE attempts=attempts+1";
		return $db->queryInsert($insertStr, false);
	}

	public function retrieveBlackList()
	{
		if ($this->blackListLoaded) { return $this->blackList; }
		$blackList = $this->getBlacklist(true);
		$this->blackList = $blackList;
		$this->blackListLoaded = true;
		return $blackList;
	}

	public function isBlackListed($msg, $groupName)
	{
		$blackList = $this->retrieveBlackList();
		$field = array();
		if (isset($msg["Subject"]))
			$field[Binaries::BLACKLIST_FIELD_SUBJECT] = $msg["Subject"];

		if (isset($msg["From"]))
			$field[Binaries::BLACKLIST_FIELD_FROM] = $msg["From"];

		if (isset($msg["Message-ID"]))
			$field[Binaries::BLACKLIST_FIELD_MESSAGEID] = $msg["Message-ID"];

		$omitBinary = false;

		foreach ($blackList as $blist)
		{
			if (preg_match('/^'.$blist['groupname'].'$/i', $groupName))
			{
				//blacklist
				if ($blist['optype'] == 1)
				{
					if (preg_match('/'.$blist['regex'].'/i', $field[$blist['msgcol']])) {
						$omitBinary = true;
					}
				}
				else if ($blist['optype'] == 2)
				{
					if (!preg_match('/'.$blist['regex'].'/i', $field[$blist['msgcol']])) {
						$omitBinary = true;
					}
				}
			}
		}

		return $omitBinary;
	}

	public function search($search, $limit=1000, $excludedcats=array())
	{
		$db = new DB();

		//
		// if the query starts with a ^ it indicates the search is looking for items which start with the term
		// still do the like match, but mandate that all items returned must start with the provided word
		//
		$words = explode(" ", $search);
		$searchsql = "";
		$intwordcount = 0;
		if (count($words) > 0)
		{
			foreach ($words as $word)
			{
				//
				// see if the first word had a caret, which indicates search must start with term
				//
				if ($intwordcount == 0 && (strpos($word, "^") === 0))
					$searchsql.= sprintf(" and b.name like %s", $db->escapeString(substr($word, 1)."%"));
				else
					$searchsql.= sprintf(" and b.name like %s", $db->escapeString("%".$word."%"));

				$intwordcount++;
			}
		}

		$exccatlist = "";
		if (count($excludedcats) > 0)
			$exccatlist = " and b.categoryID not in (".implode(",", $excludedcats).") ";

		$res = $db->query(sprintf("
					SELECT b.*,
					g.name AS group_name,
					r.guid,
					(SELECT COUNT(ID) FROM parts p WHERE p.binaryID = b.ID) as 'binnum'
					FROM binaries b
					INNER JOIN groups g ON g.ID = b.groupID
					LEFT OUTER JOIN releases r ON r.ID = b.releaseID
					WHERE 1=1 %s %s order by DATE DESC LIMIT %d ",
					$searchsql, $exccatlist, $limit));

		return $res;
	}

	public function getForReleaseId($id)
	{
		$db = new DB();
		return $db->query(sprintf("select binaries.* from binaries WHERE releaseID = %d order by relpart", $id));
	}

	public function getById($id)
	{
		$db = new DB();
		return $db->queryOneRow(sprintf("select binaries.*, collections.groupID, groups.name as groupname from binaries, collections left outer join groups on collections.groupID = groups.ID WHERE binaries.ID = %d ", $id));
	}

	public function getBlacklist($activeonly=true)
	{
		$db = new DB();

		$where = "";
		if ($activeonly)
			$where = " WHERE binaryblacklist.status = 1 ";

		return $db->query("SELECT binaryblacklist.ID, binaryblacklist.optype, binaryblacklist.status, binaryblacklist.description, binaryblacklist.groupname AS groupname, binaryblacklist.regex,
												groups.ID AS groupID, binaryblacklist.msgcol FROM binaryblacklist
												left outer JOIN groups ON groups.name = binaryblacklist.groupname
												".$where."
												ORDER BY coalesce(groupname,'zzz')");
	}

	public function getBlacklistByID($id)
	{
		$db = new DB();
		return $db->queryOneRow(sprintf("select * from binaryblacklist WHERE ID = %d ", $id));
	}

	public function deleteBlacklist($id)
	{
		$db = new DB();
		return $db->query(sprintf("DELETE FROM binaryblacklist WHERE ID = %d", $id));
	}

	public function updateBlacklist($regex)
	{
		$db = new DB();

		$groupname = $regex["groupname"];
		if ($groupname == "")
			$groupname = "null";
		else
		{
			$groupname = preg_replace("/a\.b\./i", "alt.binaries.", $groupname);
			$groupname = sprintf("%s", $db->escapeString($groupname));
		}

		$db->query(sprintf("update binaryblacklist set groupname=%s, regex=%s, status=%d, description=%s, optype=%d, msgcol=%d WHERE ID = %d ", $groupname, $db->escapeString($regex["regex"]), $regex["status"], $db->escapeString($regex["description"]), $regex["optype"], $regex["msgcol"], $regex["id"]));
	}

	public function addBlacklist($regex)
	{
		$db = new DB();

		$groupname = $regex["groupname"];
		if ($groupname == "")
			$groupname = "null";
		else
		{
			$groupname = preg_replace("/a\.b\./i", "alt.binaries.", $groupname);
			$groupname = sprintf("%s", $db->escapeString($groupname));
		}

		return $db->queryInsert(sprintf("INSERT IGNORE INTO binaryblacklist (groupname, regex, status, description, optype, msgcol) values (%s, %s, %d, %s, %d, %d) ",
			$groupname, $db->escapeString($regex["regex"]), $regex["status"], $db->escapeString($regex["description"]), $regex["optype"], $regex["msgcol"]));
	}

	public function delete($id)
	{
		$db = new DB();
		$bins = $db->query(sprintf("select ID from binaries WHERE collectionID = %d", $id));
		foreach ($bins as $bin)
			$db->query(sprintf("DELETE FROM parts WHERE binaryID = %d", $bin["ID"]));
		$db->query(sprintf("DELETE FROM binaries WHERE collectionID = %d", $id));
		$db->query(sprintf("DELETE FROM collections WHERE ID = %d", $id));
	}
}
