<?php
	require 'dbinc.php';
	require '../logbook.class.php';

	// Record the start time of the script
	$start = microtime();
	sscanf ($start,"%s %s", $microseconds, $seconds);
	$startTime = $seconds + $microseconds;

	// Read data posted from form
	$originalFileName = $_FILES['userfile']['name'];
	$tempFileName = $_FILES['userfile']['tmp_name'];
	$dxCallsign = $_POST['callsign'];

	// instantiate the LogBook object
	try
	{
		$logBook = new LogBook($dbDsn, $dbUser, $dbPassword, $dbOptions, $dbPrefix);
	}
	catch(Exception $e)
	{
		die ("Error " . $e->getCode() . " : " . $e->getMessage());
	}

	echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\"\"http://www.w3c.org/TR/1999/REC-html401-19991224/loose.dtd\">";
	echo "<html>";
	echo "<head>";
	echo "<title>Upload Log</title>";
	echo "</head>";
	echo "<body>";

	// Was a log file uploaded?
	if (is_uploaded_file ($tempFileName))
	{
		$fileType = null;
		echo "<h1>File Upload $originalFileName</h1>";
		echo "<p>Filename - $originalFileName\n";
		echo "<br>DX Callsign - $dxCallsign\n";

		try
		{
			$qsoCount = $logBook->importFile($tempFileName, $dxCallsign, $fileType, $originalFileName);
		}
		catch(Exception $e)
		{
			echo "<p>Error - Unable to import the file.\n";
			echo "<p>".$e->getMessage()."\n";
			echo "<p><A HREF=\"uploadlog.php\">Return to Log Upload Page</A>\n";
			die();
		}

		// Record the end time of the script
		$end = microtime();
		sscanf ($end,"%s %s", $microseconds, $seconds);
		$endTime = $seconds + $microseconds;

		// Calculate elapsed time for the script
		$elapsed = $endTime - $startTime;
		sscanf ($elapsed,"%5f", $elapsedTime);

		switch($fileType)
		{
			case LogBook::FILE_TYPE_ADIF:
				$fileType = 'ADIF';
				break;
			case LogBook::FILE_TYPE_CABRILLO:
				$fileType = 'Cabrillo';
				break;
			case LogBook::FILE_TYPE_ADX:
				$fileType = 'ADX';
				break;
			default:
				$fileType = '(unknown)';
				break;
		}
		echo "<P>File type loaded: $fileType";
		echo "<p>A total of $qsoCount QSOs were added to the database ";
		echo "for callsign $dxCallsign<P>";
		echo "Elapsed time = $elapsedTime seconds";

		$totalQSOCount = $logBook->getQSOCount();

		echo "<P>There are now $totalQSOCount QSOs in the database<P>";
	}
	else
	{
		// No file uploaded
		echo "<h1>No file Uploaded</h1>";
	}

	echo "<p><A HREF=\"index.php\">Return to Log Upload Page</A>\n";
	echo "</body>";
	echo "</html>";
?>

