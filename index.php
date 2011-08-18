<?php

require_once 'ical.php';

if(isset($_POST['ical_url']))
	$ical_url = htmlspecialchars($_POST['ical_url'], ENT_QUOTES);

?>
<html>
	<head>
		<title>Online iCal Parser</title>
	</head>
	<body>
		<h1>Online iCal Parser</h1>
		
		<form action="#" method="POST">
			<label for="ical_url">URL</label>
			<input type="text" id="ical_url" name="ical_url" value="<?=$ical_url;?>" />
			
			<input type="submit" name="submit_ical" value="Parse!" />
		</form>
		
		<?php
			if($ical_url)
			{
				$ical = new SG_iCalReader($ical_url);
				if(!$ical->getCalendarInfo())
				{
					echo '<p class="error">Failed to load '.$ical_url.'!</p>';
				}
				
				$information = $ical->getCalendarInfo();
				if($information)
				{
					?>
					
					<h2>Calendar: <?=$information->getTitle();?></h2>
					<p>Description: <?=$information->getDescription();?></h2>
					<p>Found <?=count($ical->getEvents());?> events:</p>
					
					<dl>
						<?php foreach($ical->getEvents() as $event): ?>
							<dt><?=$event->getSummary();?> on <?=date('r', $event->getStart());?></dt>
							<dd><?=nl2br($event->getDescription());?></dd>
						<?php endforeach; ?>
					</dl>
					<?
				}
			}
		?>
	</body>
</html>
