<html>
<head>
<!--
NN4 does not understand @import 
-->
<link rel="stylesheet" href="layout1.css" type="text/css"> 
<style type="text/css"> 
@import url(layout1.css);
</style>

<title>G4ZFE Log Search Results</title>
</head>

<body>

<DIV id=Header>
<TABLE BORDER="0" CELLSPACING="0" CELLPADDING="0" WIDTH="100%">
	<TR VALIGN="MIDDLE">
		<TD WIDTH="32">
			<A title="G4ZFE" href="http://www.g4zfe.com">G4ZFE.COM</A>
		</td>
		<TD WIDTH="80%">
			<A href="downloads.html">DOWNLOADS</A>
			&nbsp;&nbsp;&nbsp;&nbsp;
			<A href="iota.html">IOTA</A>
			&nbsp;&nbsp;&nbsp;&nbsp;
			<A href="9m2g4zfe.html">9M2/G4ZFE</A>
			&nbsp;&nbsp;&nbsp;&nbsp;
			<A href="3w2er.html">3W2ER</A>
			&nbsp;&nbsp;&nbsp;&nbsp;
			<A href="audio.html">AUDIO</A>
			&nbsp;&nbsp;&nbsp;&nbsp;
			<A href="misc.html">MISC</A>
			&nbsp;&nbsp;&nbsp;&nbsp;
			<A href="contact.html">CONTACT</A>
		</td>
	</TR>
</TABLE>
</DIV>

<DIV id="Content">
<?php
	require 'dbinc.php';             // Variables for DB connection
	require '../logbook.class.php';  // The LogBook class

	// Record the start time of the script
	$start = microtime();
	sscanf ($start,"%s %s", $microseconds, $seconds);
	$startTime = $seconds + $microseconds;

	// This function displays the modes contacted for each band (cell) as an image
	function print_cell ($value)
	{
		switch ($value)
		{
			case '0':
			echo "\n\t<td width=\"5%\">&nbsp;</td>";
			break;

			case '1':
			echo "\n\t<td width=\"5%\"><CENTER><img src=\"images/ssb.gif\" ALT=\"SSB\"></CENTER></td>";
			break;

			case '2':
			echo "\n\t<td width=\"5%\"><CENTER><img src=\"images/cw.gif\" ALT=\"CW\"></CENTER></td>";
			break;

			case '3':
			echo "\n\t<td width=\"5%\"><CENTER><img src=\"images/ssbcw.gif\" ALT=\"SSB CW\"></CENTER></td>";
			break;

			case '4':
			echo "\n\t<td width=\"5%\"><CENTER><img src=\"images/dig.gif\" ALT=\"DIG\"></CENTER></td>";
			break;

			case '5':
			echo "\n\t<td width=\"5%\"><CENTER><img src=\"images/ssbdig.gif\" ALT=\"SSB DIG\"></CENTER></td>";
			break;

			case '6':
			echo "\n\t<td width=\"5%\"><CENTER><img src=\"images/cwdig.gif\" ALT=\"CW DIG\"></CENTER></td>";
			break;

			case '7':
			echo "\n\t<td width=\"5%\"><CENTER><img src=\"images/cwssbdig.gif\" ALT=\"CW SSB DIG\"></CENTER></td>";
			break;

		}	
	}

	// This function is used to add up a whole array. It is used to check if any QSOs have been made with a
	// particular DX station
	function add_up ($runningTotal, $currentValue)
	{
		$runningTotal += $currentValue;
		return $runningTotal;
	}

	// Start of program

	// instantiate the LogBook object
	try
	{
		$logBook = new LogBook($dbDsn, $dbUser, $dbPassword, $dbOptions, $dbPrefix);
	}
	catch(Exception $e)
	{
		die ("Error " . $e->getCode() . " : " . $e->getMessage());
	}

	echo "\n<table border=\"0\" cellpadding=\"10\" cellspacing=\"10\">"
		. "\n<tr>"
		. "\n\t<td bgcolor=\"#CCCCFF\">"
		. "\n\t<h2>Full Log Search</h2>"
		. "\n\t<p>Search the log book<br>"
		. "\n\t<form action=\"search.php\" method=\"POST\">"
		. "\n\t<p>"
		. "\n\tEnter a callsign: <input type=\"text\" name=\"callsign\">"
		. "\n\t<p>"
		. "\n\t<input type=\"submit\" value=\"Search Log!\">"
		. "\n\t</form>"
		. "\n\t</td>"
		. "\n</tr>"
		. "\n</table>";

	// Read the callsign to search.
	if (isset($_POST['callsign']))
	{
		$callsign = strtoupper($_POST['callsign']);

		echo "<CENTER><H1>Log Search result for $callsign</H1></CENTER>";
		echo "<P>";

		// Fetch the list of DX callsigns available
		$dxcalls = $logBook->getDXCallsigns();

		// Create an array of the bands to be displayed. This is hard coded to make my life easier. It should
		// really be read from the database and the HTML table dynamically created. Let as a later exercise....
		$bands = array (160,80,40,30,20,17,15,12,10);

		// Initialise the 2-d array i.e. set number of QSOs on each band for each DX station to zero. This
		// make populating the HTML table a little easier.
		for ($i=0; $i < count($dxcalls); $i++)
			for ($j=0; $j < count($bands); $j++)
				$table[$dxcalls[$i]][$bands[$j]] = 0;

		// Query the database for all the QSOs for the requested callsign
		$qsos = $logBook->searchQSOs($callsign);
		if (empty($qsos))
		{
			echo "<P>Sorry no QSOs found for $callsign!<P>";
			$count = 0;
		}
		else
		{
			// Table Headings - bands
			echo "\n<center><table BORDER=\"1\" CELLSPACING=\"0\" CELLPADDING=\"5\" width=\"70%\">\n<tr>\n" .
				"\n\t<th>Callsign</th>";
			foreach ($bands as $band)
			{
				echo "\n\t<th>{$band}</th>";
			}
			echo "\n</tr>" .
				"\n<p>";

			foreach ($qsos as $row)
			{
				// Add up the number of QSOs on each band
				switch ($row["op_mode"])
				{
					case 'SSB':
						$table [$row["dxcallsign"]] [$row["band"]] += 1;
						break;

					case 'CW':
						$table [$row["dxcallsign"]] [$row["band"]] += 2;
						break;

					case 'DIG':
						$table [$row["dxcallsign"]] [$row["band"]] += 4;
						break;

				}
			}
			$count = count($qsos);

			// We have now read all the QSOs made for all the DX stations into a 2D matrix ($table)
			// Now we go through each row (DX station) and column (band)

			foreach ($table as $k => $v)
			{
				// Count the number of QSOs made with this DX station
				$total = array_reduce ($v, 'add_up');

				// None? Then don't bother displaying the row
				if ($total == 0)
					continue;

				echo "\n<tr>";
				echo "\n\t<td width=\"10%\"><center>$k</center></td>";

				foreach ($v as $k2 => $v2)
				{
					// Display QSOs made on each band
					if(in_array($k2, $bands))
					{
						print_cell($v2);
					}
				}
				echo "\n</tr>";
			}

			echo "\n</table></center>";
			echo "\n\n";
		} // End else no QSOs found

		switch ($count)
		{
			case 1:
				echo "\n<CENTER><P><P><B>Total of $count QSO with $callsign</B><P>";
				break;

			case 0:
				break;

			default:
				echo "\n<CENTER><P><P><B>Total of $count QSOs with $callsign</B><P>";
				break;
		}
	}

	// Record the end time of the script
	$end = microtime();
	sscanf ($end,"%s %s", $microseconds, $seconds);
	$endTime = $seconds + $microseconds;

	// Calculate elapsed time for the script
	$elapsed = $endTime - $startTime;
	sscanf ($elapsed,"%5f", $elapsedTime);

	// Display summary info
	
	// Count the number of QSOs in the database
	$totalQSOCount = $logBook->getQSOCount();
			
	echo "\n<P>There are $totalQSOCount QSOs in the Database<P>";

	echo "\n<P><FONT SIZE=\"-2\">Page load took $elapsedTime seconds</FONT></CENTER>";
	
?>

<BR>
<P>
<A href="search.html">&lt; Return to Log Search Page</A>
</P>
</DIV>


<DIV id=Menu>
<P>
<A HREF="downloads.html">Download the Java Log Search Applet</A>
<P>
<A HREF="downloads.html">Download the MySQL Logbook Database</A>
<P>
</DIV>


<body>
</html>
