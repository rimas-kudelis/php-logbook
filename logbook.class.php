<?php

class LogBook
{
	const FILE_TYPE_UNKNOWN = 0;
	const FILE_TYPE_ADX = 1;
	const FILE_TYPE_ADIF = 2;
	const FILE_TYPE_CABRILLO = 3;

	private $db;
	private $pfx; // database table prefix
	private $addQSOStatement;
	private $addImportedFileInfoStatement;
	private $searchQSOsAllStatement;
	private $searchQSOsByDXCallsignStatement;	

	public function __construct($dbDsn, $dbUser='', $dbPassword='', $dbOptions=null, $pfx='')
	{
		$this->db = new PDO($dbDsn, $dbUser, $dbPassword, $dbOptions);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		$this->pfx = $pfx;
	}
	
	protected function getDXCallsignId($callsign)
	{
		$callsign = $this->db->quote($callsign);
		$query = "SELECT id FROM {$this->pfx}dxstation WHERE dxcallsign={$callsign}";
		if ($result = $this->db->query($query))
		{
			return ($result->fetchColumn());
		}
	}

	public function getDXCallsigns()
	{
		$query = "SELECT dxcallsign FROM {$this->pfx}dxstation ORDER BY ordering, dxcallsign";
		if ($result = $this->db->query($query))
		{
			return ($result->fetchAll(PDO::FETCH_COLUMN, 0));
		}
	}

	public function getQSOCount()
	{
		$query = "SELECT COUNT(id) FROM {$this->pfx}qsos";
		if ($result = $this->db->query($query))
		{
			return ($result->fetchColumn());
		}
	}
	
	public function getImportedFileInfo($limit=0)
	{
		$limit = (int)$limit;
		if (empty($limit))
		{
			$query = "SELECT * FROM {$this->pfx}logfiles ORDER BY loaded DESC";
		}
		else
		{
			$query = "SELECT * FROM {$this->pfx}logfiles ORDER BY loaded DESC LIMIT {$limit}";
		}
		if ($result = $this->db->query($query))
		{
			return ($result->fetchAll());
		}
	}

	protected function addImportedFileInfo($fileName, $qsoCount, $fileType)
	{
		if (is_null($this->addImportedFileInfoStatement))
		{
			$query = "INSERT INTO {$this->pfx}logfiles
				SET filename = :fileName,
					qsos = :qsoCount,
					filetype = :fileType,
					loaded = NOW()";
			$this->addImportedFileInfoStatement = $this->db->prepare($query);
		}

		return $this->addImportedFileInfoStatement->execute(array(
			':fileName' => $fileName,
			':qsoCount' => $qsoCount,
			':fileType' => $fileType));
	}

	/**
	 * Insert QSO into the database
	 * Parameters:
	 * $callsign   - contacted station's callsign
	 * $opMode     - QSO mode
	 * $band       - QSO band
	 * $dxCallsign - logging operator's callsign 
	 */
	public function addQSO($callsign, $opMode, $band, $dxCallsign)
	{
		// prepare the statement if it's the first time we run it
		if (is_null($this->addQSOStatement))
		{
			$query = "INSERT INTO {$this->pfx}qsos
				SET callsign = :callsign,
					op_mode = :opMode,
					band = :band,
					fk_dxstn = :dxCallsign";
			$this->addQSOStatement = $this->db->prepare($query);
		}

		if (empty($callsign) || empty($opMode) || empty($band) || empty($dxCallsign))
		{
			throw new Exception('One of the values passed is missing or empty');
		}

		return $this->addQSOStatement->execute(array(
			':callsign' => $callsign,
			':opMode' => $opMode,
			':band' => $band,
			':dxCallsign' => $dxCallsign
			));
	}

	/**
	 * Get the list of QSOs by callsigns of participating parties
	 * Parameters:
	 * $callsign   - contacted station's callsign
	 * $dxCallsign - logging operator's callsign
	 */
	public function searchQSOs($callsign, $dxCallsign=null)
	{
		if (empty($callsign))
		{
			return false;
		}
		if (empty($dxCallsign))
		{
			if (is_null($this->searchQSOsAllStatement))
			{
				$query = "SELECT DISTINCT
						dx.dxcallsign, 
						q.op_mode, 
						q.band
					FROM {$this->pfx}dxstation dx
						LEFT JOIN {$this->pfx}qsos q ON q.fk_dxstn = dx.id
					WHERE q.callsign = :callsign
					ORDER by dx.dxcallsign, q.band DESC";
				$this->searchQSOsAllStatement = $this->db->prepare($query);
			}

			$result = $this->searchQSOsAllStatement->execute(array(
				':callsign' => $callsign,
				));
		}
		else
		{
			if (is_null($this->searchQSOsByDXCallsignStatement))
			{
				$query = "SELECT DISTINCT
						dx.dxcallsign, 
						q.op_mode, 
						q.band
					FROM {$this->pfx}dxstation dx
						LEFT JOIN {$this->pfx}qsos q ON q.fk_dxstn = dx.id
					WHERE q.callsign = :callsign
						AND dx.dxcallsign = :dxCallsign
					ORDER by q.band DESC";
				$this->searchQSOsByDXCallsignStatement = $this->db->prepare($query);
			}

			$result = $this->searchQSOsByDXCallsignStatement->execute(array(
				':callsign' => $callsign,
				':dxCallsign' => $dxCallsign,
				));
		}

		return $result->fetchAll();
	}

	/**
	 * Read a file and insert each QSO into the database
	 * Parameters:
	 * $fileName     - path of the file to import
	 * $dxCallsign   - operator callsign
	 * &$fileType    - type of the file (optional; see FILE_TYPE_* constants of this class).
	 *                 if $fileType is passed and considered empty, its value will be set
	 *                 to one of the FILE_TYPE_* constants
	 * $origFileName - original file name to store in the log (if differs)
	 */
	public function importFile($fileName, $dxCallsign, &$fileType=null, $origFileName=null)
	{
		if (empty($fileType))
		{
			$fileType = $this->detectFileType($fileName);
		}

		$dxCallsignId = $this->getDXCallsignId($dxCallsign);

		switch($fileType)
		{
			case self::FILE_TYPE_ADIF:
				return $this->importADIFFile($fileName, $dxCallsignId, $origFileName);
				break;
			case self::FILE_TYPE_CABRILLO:
				return $this->importCabrilloFile($fileName, $dxCallsignId, $origFileName);
				break;
			case self::FILE_TYPE_ADX:
				// TODO: implement the importADXFile method
				//return $this->importADXFile($fileName, $dxCallsignId, $origFileName);
				//break;
			case self::FILE_TYPE_UNKNOWN:
			default:
				throw new Exception('File type unsupported or not detected correctly');
				break;
		}
	}

	/**
	 * Detect type of a log file
	 * Parameters:
	 * $fileName - path of the file to inspect
	 */
	public function detectFileType($fileName)
	{
		if (!($fileHandle = fopen ($fileName, "r")))
		{
			throw new Exception("Could not open the log file {$fileName}");
		}

		// Go to the beginning of the file and read its first line
		$line = fgets ($fileHandle, 1024);

		// Cabrillo files start with this string
		if (stripos($line, 'START-OF-LOG:') !== false)
		{
			return self::FILE_TYPE_CABRILLO;
		}
		else do
		{
			// ADIF files might (or might not) have a free-form header that ends with <EOH>
			// Either way, they will have lines with <CALL:
			if ((stripos($line, '<EOH>') !== false) || (stripos($line, '<CALL:') !== false))
			{
				return self::FILE_TYPE_ADIF;
			}
			// ADX are XML files with ADX as root element
			else if (strpos($line, '<ADX>') !== false)
			{
				return self::FILE_TYPE_ADX;
			}
		} while ($line = fgets ($fileHandle, 1024));

		// File not recognized
		return self::FILE_TYPE_UNKNOWN;
	}

	/**
	 * Read an ADIF file and insert each QSO into the database
	 * Parameters:
	 * $fileName - path of the ADIF file to import
	 * $dxCallsignId - FK to logbook
	 * $origFileName - original file name to store in the log (if differs)
	 */
	protected function importADIFFile($fileName, $dxCallsignId, $origFileName=null)
	{
		if (!($fileHandle = fopen ($fileName, "r")))
		{
			throw new Exception("Could not open the log file {$fileName}");
		}

		$qsoCount = 0;

		$qsoData = array ('band' => '',
			'mode' => '',
			'freq' => '',
			'call' => '');

		// get the first line and skip the header, if any
		$line = fgets($fileHandle, 1024);
		if (($line !== false) && (strpos($line, '<') !== 0))
		{
			while (($line = fgets($fileHandle, 1024)) !== false)
			{
				set_time_limit(30);
				if (stripos($line, '<EOH>') !== false)
				{
					$parts = preg_split('/<EOH>/i', $line, 2);
					$line = $parts[1];
					break;
				}
			}
		}

		// get and parse whole record, even if it spans multiple lines
		while ($line !== false)
		{
			$record = '';
			while (($line !== false) && (stripos($line, '<EOR>') === false))
			{
				$record .= $line . "\r\n";
				$line = fgets($fileHandle, 1024);
				set_time_limit(30);
				if ($line === false)
				{
					if (trim($record) != '')
					{
						throw new Exception ('ADIF error: unexpected end of file');
					}
					break 2;
				}
			}
			$parts = preg_split('/<EOR>/i', $line, 2);
			$record .= $parts[0];
			$line = $parts[1];

			$this->readADIFQSO($record, $qsoData);

			// If no <BAND> data has been found then convert frequency to band
			if (($qsoData['band'] === '') && ($qsoData['freq'] !== ''))
			{
				$qsoData['band'] = $this->frequencyToBand($qsoData['freq']);
			}


			// Insert QSO into the database
			$this->addQSO($qsoData['call'], $qsoData['mode'], $qsoData['band'], $dxCallsignId);

			$qsoCount++;
		}

		if (empty($origFileName))
		{
			$origFileName = $fileName;
		}

		$this->addImportedFileInfo($origFileName, $qsoCount, 'ADIF');

		return $qsoCount;
	}

	/**
	 * Parse a QSO record from the ADIF file
	 * Parameters:
	 * $record - record contents
	 * $qsoData - array to put parsed data into
	 */
	protected function readADIFQSO ($record, &$qsoData)
	{
		$record = strtoupper ($record);

		// Read Callsign
		if ($s = stristr($record,"<CALL"))
		{
			$values = sscanf ($s, "<CALL:%d>%s ", $length, $qsoData['call']);

			if ($values != 2)
			{
				sscanf ($s, "<CALL:%d:%c>%s ", $length, $dummy, $qsoData['call']);
			}
			$qsoData['call'] = strtoupper(substr($qsoData['call'], 0, $length));
		}

		// Read Band
		if ($s = stristr($record,"<BAND"))
		{
			$values = sscanf ($s, "<BAND:%d>%s ", $length, $qsoData['band']);

			if ($values != 2)
			{
				sscanf ($s, "<BAND:%d:%c>%s ", $length,$dummy,$qsoData['band']);
			}
			$qsoData['band'] = strtoupper(substr($qsoData['band'], 0, $length));

			// Strip the 'M off e.g. 40M
			// TODO: change this to work according to the ADIF spec (cm and mm are also possible)
			if (($pos = stripos ($qsoData['band'], 'M')) != NULL)
			{
				$qsoData['band'][$pos] = ' ';
			}
		}

		// Read Mode
		if ($s = stristr($record,"<MODE"))
		{
			$values = sscanf ($s, "<MODE:%d>%s ", $length, $qsoData['mode']);

			if ($values != 2)
			{
				sscanf ($s, "<MODE:%d:%c>%s ", $length, $dummy, $qsoData['mode']);
			}
			$qsoData['mode'] = strtoupper(substr($qsoData['mode'], 0, $length));

			switch ($qsoData['mode'])
			{
				// Convert all Digital modes to 'DIG'
				case "PSK31":
				case "PSK63":
				case "BPSK31":
				case "BPSK63":
				case "RTTY":
				case "MFSK16":
				case "WSJT":
				case "FSK441":
				case "JT6M":
					$qsoData['mode'] = "DIG";
					break;

				// Convert all Phone modes to SSB
				case "USB":
				case "LSB":
				case "FM":
				case "AM":
					$qsoData['mode'] = "SSB";
					break;
			}
		}

		// Read Frequency (e.g. if Band is not present in the record)
		if (($s = stristr($record,"<FREQ")) && ($qsoData['band'] == ''))
		{
			$values = sscanf ($s, "<FREQ:%d>%s ", $length, $qsoData['freq']);

			if ($values != 2)
			{
				sscanf ($s, "<FREQ:%d:%c>%s ", $length, $dummy, $qsoData['freq']);
			}
			$qsoData['freq'] = substr($qsoData['freq'], 0, $length);
		}
	}

	/**
	 * Read a Cabrillo file and insert each QSO into the database
	 * Parameters:
	 * $fileName   - path of the Cabrillo file to import
	 * $dxCallsignId - FK to logbook
	 * $origFileName - original file name to store in the log (if differs)
	 */
	protected function importCabrilloFile($fileName, $dxCallsignId, $origFileName)
	{
		if (!($fileHandle = fopen ($fileName, "r")))
		{
			throw new Exception("Could not open the log file {$fileName}");
		}

		// Initialise the array
		$qsoData = array ('freq' => '',
			'mode' => '',
			'call' => '');

		// Read the Cabrillo file until we reach the "CONTEST:" tag
		while (fscanf ($fileHandle, "%s %s", $tag, $value))
		{
			if (!strcasecmp($tag, "CONTEST:"))
			{
				// Read the contest type so that we can parse the file
				$contestType = $value;
				//echo "<p>Cabrillo Contest type is $contestType <P>\n";
				break;
			}
		}

		// Keep a count of the number of QSOs added to the database
		$qsoCount = 0;

		// Read each line of the log file
		while ($line = fgets ($fileHandle,1024))
		{
			$lineParts = explode (' ', $line);

			// Skip Cabrillo header lines
			if (!strcasecmp($lineParts[0], "QSO:"))
			{
				// Read one line of QSO data from the Cabrillo file
				$this->readCabrilloQSO ($contestType, $line, $qsoData);	
			}
			else
			{
				// Continue reading until the "QSO" tag
				continue;
			}

			// Convert frequency to band
			$band = $this->frequencyToBand($qsoData['freq']);

			// Cabrillo logs contain mode as "CW/PH/RY"
			// Convert PH to SSB
			if (!strcasecmp($qsoData['mode'],"PH"))
			{
				$qsoData['mode'] = 'SSB';
			}

			// Convert RY to DIG
			if (!strcasecmp($qsoData['mode'],"RY"))
			{
				$qsoData['mode'] = 'DIG';
			}
	
			// Insert QSO into the database
			$this->addQSO($qsoData['call'], $qsoData['mode'], $band, $dxCallsignId);

			$qsoCount++;
		}

		if (empty($origFileName))
		{
			$origFileName = $fileName;
		}

		$this->addImportedFileInfo($origFileName, $qsoCount, 'Cabrillo');

		return $qsoCount;
	}

	/**
	 * Function to read one line of QSO data from the Cabrillo file and parse according to the contest type
	 * Parameters:
	 * $contestType - Cabrillo contest type (determines the number of columns in the QSO data)
	 * $line - the QSO: line from the Cabrillo file
	 * &$qsoData - array to put parsed data into
	 */
	protected function readCabrilloQSO($contestType, $line, &$qsoData)
	{
		switch ($contestType)
		{
			case "ARRL-VHF-SEP":
			case "DXPEDITION":
				// This Cabrillo format has 9 columns (including QSO: column)
				sscanf ($line, "%s %s %s %s %s %s %s %s %s",
				$dummy,
				$qsoData['freq'],
				$qsoData['mode'],
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$qsoData['call'],
				$dummy);
				break;

			case "RSGB-IOTA":
			case "CQ-WW-RTTY":
				// This Cabrillo format has 13 columns (including QSO: column)
				sscanf ($line, "%s %s %s %s %s %s %s %s %s %s %s %s %s",
				$dummy,
				$qsoData['freq'],
				$qsoData['mode'],
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$qsoData['call'],
				$dummy,
				$dummy,
				$dummy);
				break;

			case "RSGB 21":
				// This Cabrillo format has 13 columns (including QSO: column)
				sscanf ($line, "%s %s %s %s %s %s %s %s %s %s %s %s %s",
				$dummy,
				$qsoData['freq'],
				$qsoData['mode'],
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$qsoData['call'],
				$dummy,
				$dummy,
				$dummy,
				$dummy);
				break;

			case "ARRL-SS-CW":
				// This Cabrillo format has 15 columns (including QSO: column)
				sscanf ($line, "%s %s %s %s %s %s %s %s %s %s %s %s %s %s %s",
				$dummy,
				$qsoData['freq'],
				$qsoData['mode'],
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$qsoData['call'],
				$dummy,
				$dummy,
				$dummy,
				$dummy);
				break;
			
 			case "IARU":
			case "AP-SPRINT":
			case "ARRL-10":
			case "ARRL-160":
			case "ARRL-DX":
			case "CQ-WPX":
      		default:
            	// The default Cabrillo format has 11 columns (inluding QSO: column)
				sscanf ($line, "%s %s %s %s %s %s %s %s %s %s %s",
				$dummy,
				$qsoData['freq'],
				$qsoData['mode'],
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$dummy,
				$qsoData['call'],
				$dummy,
				$dummy);
            	break;
		}
		if (!strcasecmp($qsoData['freq'],'1.2G'))
		{
			$qsoData['freq'] = 1240;
		}
		else if (!strcasecmp($qsoData['freq'],'75G'))
		{
			$qsoData['freq'] = 75500;
		}
		else if (!strcasecmp($qsoData['freq'],'119G'))
		{
			$qsoData['freq'] = 119980;
		}
		else if (($pos = stripos($qsoData['freq'], 'G')) !== false)
		{
			// frequency in GHz
			$qsoData['freq'] = substr($qsoData['freq'], 0, $pos) * 1000;
		}
		else if ($qsoData['freq'] > 1000)
		{
			// frequency in KHz
			$qsoData['freq'] /= 1000;
		}
	}

	/**
	 * Function to convert frequency (e.g. 21.1234) to band (e.g. 15),
	 * according to ADIF specification Band Enumeration table
	 * Parameters:
	 * $frequency - frequency in MHz to convert to band
	 */
	public function frequencyToBand($frequency)
	{
		// the following mapping is based on
		// http://www.adif.org/adif227.htm#Band%20Enumeration
		$bands = array(
			array('band' => 2190, 'lfreq' => 0.136, 'ufreq' => 0.137),
			array('band' => 560, 'lfreq' => 0.501, 'ufreq' => 0.504),
			array('band' => 160, 'lfreq' => 1.8, 'ufreq' => 2.0),
			array('band' => 80, 'lfreq' => 3.5, 'ufreq' => 4.0),
			array('band' => 60, 'lfreq' => 5.102, 'ufreq' => 5.404),
			array('band' => 40, 'lfreq' => 7.0, 'ufreq' => 7.3),
			array('band' => 30, 'lfreq' => 10.0, 'ufreq' => 10.15),
			array('band' => 20, 'lfreq' => 14.0, 'ufreq' => 14.35),
			array('band' => 17, 'lfreq' => 18.068, 'ufreq' => 18.168),
			array('band' => 15, 'lfreq' => 21.0, 'ufreq' => 21.45),
			array('band' => 12, 'lfreq' => 24.890, 'ufreq' => 24.99),
			array('band' => 10, 'lfreq' => 28.0, 'ufreq' => 29.7),
			array('band' => 6, 'lfreq' => 50, 'ufreq' => 54),
			array('band' => 4, 'lfreq' => 70, 'ufreq' => 71),
			array('band' => 2, 'lfreq' => 144, 'ufreq' => 148),
			array('band' => 1.25, 'lfreq' => 222, 'ufreq' => 225),
			array('band' => 0.7, 'lfreq' => 420, 'ufreq' => 450),
			array('band' => 0.33, 'lfreq' => 902, 'ufreq' => 928),
			array('band' => 0.23, 'lfreq' => 1240, 'ufreq' => 1300),
			array('band' => 0.13, 'lfreq' => 2300, 'ufreq' => 2450),
			array('band' => 0.09, 'lfreq' => 3300, 'ufreq' => 3500),
			array('band' => 0.06, 'lfreq' => 5650, 'ufreq' => 5925),
			array('band' => 0.03, 'lfreq' => 10000, 'ufreq' => 10500),
			array('band' => 0.0125, 'lfreq' => 24000, 'ufreq' => 24250),
			array('band' => 0.006, 'lfreq' => 47000, 'ufreq' => 47200),
			array('band' => 0.004, 'lfreq' => 75500, 'ufreq' => 81000),
			array('band' => 0.0025, 'lfreq' => 119980, 'ufreq' => 120020),
			array('band' => 0.002, 'lfreq' => 142000, 'ufreq' => 149000),
			array('band' => 0.001, 'lfreq' => 241000, 'ufreq' => 250000),
			// The last entry is to account for the "300G" option
			// mentioned in http://www.kkn.net/~trey/cabrillo/qso-template.html
			array('band' => 0.0009, 'lfreq' => 300000, 'ufreq' => 400000),
			);
		foreach ($bands as $row)
		{
			if (($frequency >= $row['lfreq']) && ($frequency <= $row['ufreq']))
			{
				return $row['band'];
			}
		}

		throw new Exception("Unable to convert frequency of {$frequency} MHz to band");
	}
}