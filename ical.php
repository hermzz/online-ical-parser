<?php

define('SG_ICALREADER_VERSION', '0.7.0');

/**
 * A simple iCal parser. Should take care of most stuff for ya
 * http://github.com/fangel/SG-iCalendar
 *
 * Roadmap:
 *  * Finish FREQUENCY-parsing.
 *  * Add API for recurring events
 *
 * A simple example:
 * <?php
 * $ical = new SG_iCalReader("http://example.com/calendar.ics");
 * foreach( $ical->getEvents() As $event ) {
 *   // Do stuff with the event $event
 * }
 * ?>
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @author xonev (C) 2010
 * @author Tanguy Pruvot (C) 2010
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal {

	//objects
	public $information; //SG_iCal_VCalendar
	public $timezones;   //SG_iCal_VTimeZone

	protected $events; //SG_iCal_VEvent[]

	/**
	 * Constructs a new iCalReader. You can supply the url now, or later using setUrl
	 * @param $url string
	 */
	public function __construct($url = false) {

		$this->setUrl($url);
	}

	/**
	 * Sets (or resets) the url this reader reads from.
	 * @param $url string
	 */
	public function setUrl( $url = false ) {
		if( $url !== false ) {
			SG_iCal_Parser::Parse($url, $this);
		}
	}

	/**
	 * Returns the main calendar info. You can then query the returned
	 * object with ie getTitle().
	 * @return SG_iCal_VCalendar
	 */
	public function getCalendarInfo() {
		return $this->information;
	}

	/**
	 * Sets the calendar info for this calendar
	 * @param SG_iCal_VCalendar $info
	 */
	public function setCalendarInfo( SG_iCal_VCalendar $info ) {
		$this->information = $info;
	}


	/**
	 * Returns a given timezone for the calendar. This is mainly used
	 * by VEvents to adjust their date-times if they have specified a
	 * timezone.
	 *
	 * If no timezone is given, all timezones in the calendar is
	 * returned.
	 *
	 * @param $tzid string
	 * @return SG_iCal_VTimeZone
	 */
	public function getTimeZoneInfo( $tzid = null ) {
		if( $tzid == null ) {
			return $this->timezones;
		} else {
			if ( !isset($this->timezones)) {
				return null;
			}
			foreach( $this->timezones AS $tz ) {
				if( $tz->getTimeZoneId() == $tzid ) {
					return $tz;
				}
			}
			return null;
		}
	}

	/**
	 * Adds a new timezone to this calendar
	 * @param SG_iCal_VTimeZone $tz
	 */
	public function addTimeZone( SG_iCal_VTimeZone $tz ) {
		$this->timezones[] = $tz;
	}

	/**
	 * Returns the events found
	 * @return array
	 */
	public function getEvents() {
		return $this->events;
	}

	/**
	 * Adds a event to this calendar
	 * @param SG_iCal_VEvent $event
	 */
	public function addEvent( SG_iCal_VEvent $event ) {
		$this->events[] = $event;
	}
}

/**
 * For legacy reasons, we keep the name SG_iCalReader..
 */
class SG_iCalReader extends SG_iCal {}

/**
 * A class to store Frequency-rules in. Will allow a easy way to find the
 * last and next occurrence of the rule.
 *
 * No - this is so not pretty. But.. ehh.. You do it better, and I will
 * gladly accept patches.
 *
 * Created by trail-and-error on the examples given in the RFC.
 *
 * TODO: Update to a better way of doing calculating the different options.
 * Instead of only keeping track of the best of the current dates found
 * it should instead keep a array of all the calculated dates within the
 * period.
 * This should fix the issues with multi-rule + multi-rule interference,
 * and make it possible to implement the SETPOS rule.
 * By pushing the next period onto the stack as the last option will
 * (hopefully) remove the need for the awful simpleMode
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal_Freq {
	protected $weekdays = array('MO'=>'monday', 'TU'=>'tuesday', 'WE'=>'wednesday', 'TH'=>'thursday', 'FR'=>'friday', 'SA'=>'saturday', 'SU'=>'sunday');
	protected $knownRules = array('month', 'weekno', 'day', 'monthday', 'yearday', 'hour', 'minute'); //others : 'setpos', 'second'
	protected $ruleModifiers = array('wkst');
	protected $simpleMode = true;

	protected $rules = array('freq'=>'yearly', 'interval'=>1);
	protected $start = 0;
	protected $freq = '';

	protected $excluded; //EXDATE
	protected $added;    //RDATE

	protected $cache; // getAllOccurrences()

	/**
	 * Constructs a new Freqency-rule
	 * @param $rule string
	 * @param $start int Unix-timestamp (important : Need to be the start of Event)
	 * @param $excluded array of int (timestamps), see EXDATE documentation
	 * @param $added array of int (timestamps), see RDATE documentation
	 */
	public function __construct( $rule, $start, $excluded=array(), $added=array()) {
		$this->start = $start;
		$this->excluded = array();

		$rules = array();
		foreach( explode(';', $rule) AS $v) {
			list($k, $v) = explode('=', $v);
			$this->rules[ strtolower($k) ] = $v;
		}

		if( isset($this->rules['until']) && is_string($this->rules['until']) ) {
			$this->rules['until'] = strtotime($this->rules['until']);
		}
		$this->freq = strtolower($this->rules['freq']);

		foreach( $this->knownRules AS $rule ) {
			if( isset($this->rules['by' . $rule]) ) {
				if( $this->isPrerule($rule, $this->freq) ) {
					$this->simpleMode = false;
				}
			}
		}

		if(!$this->simpleMode) {
			if(! (isset($this->rules['byday']) || isset($this->rules['bymonthday']) || isset($this->rules['byyearday']))) {
				$this->rules['bymonthday'] = date('d', $this->start);
			}
		}

		//set until, and cache
		if( isset($this->rules['count']) ) {

			$cache[$ts] = $ts = $this->start;
			for($n=1; $n < $this->rules['count']; $n++) {
				$ts = $this->findNext($ts);
				$cache[$ts] = $ts;
			}
			$this->rules['until'] = $ts;

			//EXDATE
			if (!empty($excluded)) {
				foreach($excluded as $ts) {
					unset($cache[$ts]);
				}
			}
			//RDATE
			if (!empty($added)) {
				$cache = $cache + $added;
				asort($cache);
			}

			$this->cache = array_values($cache);
		}

		$this->excluded = $excluded;
		$this->added = $added;
	}


	/**
	 * Returns all timestamps array(), build the cache if not made before
	 * @return array
	 */
	public function getAllOccurrences() {
		if (empty($this->cache)) {
			//build cache
			$next = $this->firstOccurrence();
			while ($next) {
				$cache[] = $next;
				$next = $this->findNext($next);
			}
			if (!empty($this->added)) {
				$cache = $cache + $this->added;
				asort($cache);
			}
			$this->cache = $cache;
		}
		return $this->cache;
	}

	/**
	 * Returns the previous (most recent) occurrence of the rule from the
	 * given offset
	 * @param int $offset
	 * @return int
	 */
	public function previousOccurrence( $offset ) {
		if (!empty($this->cache)) {
			$t2=$this->start;
			foreach($this->cache as $ts) {
				if ($ts >= $offset)
					return $t2;
				$t2 = $ts;
			}
		} else {
			$ts = $this->start;
			while( ($t2 = $this->findNext($ts)) < $offset) {
				if( $t2 == false ){
					break;
				}
				$ts = $t2;
			}
		}
		return $ts;
	}

	/**
	 * Returns the next occurrence of this rule after the given offset
	 * @param int $offset
	 * @return int
	 */
	public function nextOccurrence( $offset ) {
		if ($offset < $this->start)
			return $this->firstOccurrence();
		return $this->findNext($offset);
	}

	/**
	 * Finds the first occurrence of the rule.
	 * @return int timestamp
	 */
	public function firstOccurrence() {
		$t = $this->start;
		if (in_array($t, $this->excluded))
			$t = $this->findNext($t);
		return $t;
	}

	/**
	 * Finds the absolute last occurrence of the rule from the given offset.
	 * Builds also the cache, if not set before...
	 * @return int timestamp
	 */
	public function lastOccurrence() {
		//build cache if not done
		$this->getAllOccurrences();
		//return last timestamp in cache
		return end($this->cache);
	}

	/**
	 * Calculates the next time after the given offset that the rule
	 * will apply.
	 *
	 * The approach to finding the next is as follows:
	 * First we establish a timeframe to find timestamps in. This is
	 * between $offset and the end of the period that $offset is in.
	 *
	 * We then loop though all the rules (that is a Prerule in the
	 * current freq.), and finds the smallest timestamp inside the
	 * timeframe.
	 *
	 * If we find something, we check if the date is a valid recurrence
	 * (with validDate). If it is, we return it. Otherwise we try to
	 * find a new date inside the same timeframe (but using the new-
	 * found date as offset)
	 *
	 * If no new timestamps were found in the period, we try in the
	 * next period
	 *
	 * @param int $offset
	 * @return int
	 */
	public function findNext($offset) {
		if (!empty($this->cache)) {
			foreach($this->cache as $ts) {
				if ($ts > $offset)
					return $ts;
			}
		}

		$debug = false;

		//make sure the offset is valid
		if( $offset === false || (isset($this->rules['until']) && $offset > $this->rules['until']) ) {
			if($debug) echo 'STOP: ' . date('r', $offset) . "\n";
			return false;
		}

		$found = true;

		//set the timestamp of the offset (ignoring hours and minutes unless we want them to be
		//part of the calculations.
		if($debug) echo 'O: ' . date('r', $offset) . "\n";
		$hour = (in_array($this->freq, array('hourly','minutely')) && $offset > $this->start) ? date('H', $offset) : date('H', $this->start);
		$minute = (($this->freq == 'minutely' || isset($this->rules['byminute'])) && $offset > $this->start) ? date('i', $offset) : date('i', $this->start);
		$t = mktime($hour, $minute, date('s', $this->start), date('m', $offset), date('d', $offset), date('Y',$offset));
		if($debug) echo 'START: ' . date('r', $t) . "\n";

		if( $this->simpleMode ) {
			if( $offset < $t ) {
				$ts = $t;
				if ($ts && in_array($ts, $this->excluded))
					$ts = $this->findNext($ts);
			} else {
				$ts = $this->findStartingPoint( $t, $this->rules['interval'], false );
				if( !$this->validDate( $ts ) ) {
					$ts = $this->findNext($ts);
				}
			}
			return $ts;
		}

		$eop = $this->findEndOfPeriod($offset);
		if($debug) echo 'EOP: ' . date('r', $eop) . "\n";

		foreach( $this->knownRules AS $rule ) {
			if( $found && isset($this->rules['by' . $rule]) ) {
				if( $this->isPrerule($rule, $this->freq) ) {
					$subrules = explode(',', $this->rules['by' . $rule]);
					$_t = null;
					foreach( $subrules AS $subrule ) {
						$imm = call_user_func_array(array($this, 'ruleBy' . $rule), array($subrule, $t));
						if( $imm === false ) {
							break;
						}
						if($debug) echo strtoupper($rule) . ': ' . date('r', $imm) . ' A: ' . ((int) ($imm > $offset && $imm < $eop)) . "\n";
						if( $imm > $offset && $imm < $eop && ($_t == null || $imm < $_t) ) {
							$_t = $imm;
						}
					}
					if( $_t !== null ) {
						$t = $_t;
					} else {
						$found = $this->validDate($t);
					}
				}
			}
		}

		if( $offset < $this->start && $this->start < $t ) {
			$ts = $this->start;
		} else if( $found && ($t != $offset)) {
			if( $this->validDate( $t ) ) {
				if($debug) echo 'OK' . "\n";
				$ts = $t;
			} else {
				if($debug) echo 'Invalid' . "\n";
				$ts = $this->findNext($t);
			}
		} else {
			if($debug) echo 'Not found' . "\n";
			$ts = $this->findNext( $this->findStartingPoint( $offset, $this->rules['interval'] ) );
		}
		if ($ts && in_array($ts, $this->excluded))
			return $this->findNext($ts);

		return $ts;
	}

	/**
	 * Finds the starting point for the next rule. It goes $interval
	 * 'freq' forward in time since the given offset
	 * @param int $offset
	 * @param int $interval
	 * @param boolean $truncate
	 * @return int
	 */
	private function findStartingPoint( $offset, $interval, $truncate = true ) {
		$_freq = ($this->freq == 'daily') ? 'day__' : $this->freq;
		$t = '+' . $interval . ' ' . substr($_freq,0,-2) . 's';
		if( $_freq == 'monthly' && $truncate ) {
			if( $interval > 1) {
				$offset = strtotime('+' . ($interval - 1) . ' months ', $offset);
			}
			$t = '+' . (date('t', $offset) - date('d', $offset) + 1) . ' days';
		}

		$sp = strtotime($t, $offset);

		if( $truncate ) {
			$sp = $this->truncateToPeriod($sp, $this->freq);
		}

		return $sp;
	}

	/**
	 * Finds the earliest timestamp posible outside this perioid
	 * @param int $offset
	 * @return int
	 */
	public function findEndOfPeriod($offset) {
		return $this->findStartingPoint($offset, 1);
	}

	/**
	 * Resets the timestamp to the beginning of the
	 * period specified by freq
	 *
	 * Yes - the fall-through is on purpose!
	 *
	 * @param int $time
	 * @param int $freq
	 * @return int
	 */
	private function truncateToPeriod( $time, $freq ) {
		$date = getdate($time);
		switch( $freq ) {
			case "yearly":
				$date['mon'] = 1;
			case "monthly":
				$date['mday'] = 1;
			case "daily":
				$date['hours'] = 0;
			case 'hourly':
				$date['minutes'] = 0;
			case "minutely":
				$date['seconds'] = 0;
				break;
			case "weekly":
				if( date('N', $time) == 1) {
					$date['hours'] = 0;
					$date['minutes'] = 0;
					$date['seconds'] = 0;
				} else {
					$date = getdate(strtotime("last monday 0:00", $time));
				}
				break;
		}
		$d = mktime($date['hours'], $date['minutes'], $date['seconds'], $date['mon'], $date['mday'], $date['year']);
		return $d;
	}

	/**
	 * Applies the BYDAY rule to the given timestamp
	 * @param string $rule
	 * @param int $t
	 * @return int
	 */
	private function ruleByday($rule, $t) {
		$dir = ($rule{0} == '-') ? -1 : 1;
		$dir_t = ($dir == 1) ? 'next' : 'last';


		$d = $this->weekdays[substr($rule,-2)];
		$s = $dir_t . ' ' . $d . ' ' . date('H:i:s',$t);

		if( $rule == substr($rule, -2) ) {
			if( date('l', $t) == ucfirst($d) ) {
				$s = 'today ' . date('H:i:s',$t);
			}

			$_t = strtotime($s, $t);

			if( $_t == $t && in_array($this->freq, array('monthly', 'yearly')) ) {
				// Yes. This is not a great idea.. but hey, it works.. for now
				$s = 'next ' . $d . ' ' . date('H:i:s',$t);
				$_t = strtotime($s, $_t);
			}

			return $_t;
		} else {
			$_f = $this->freq;
			if( isset($this->rules['bymonth']) && $this->freq == 'yearly' ) {
				$this->freq = 'monthly';
			}
			if( $dir == -1 ) {
				$_t = $this->findEndOfPeriod($t);
			} else {
				$_t = $this->truncateToPeriod($t, $this->freq);
			}
			$this->freq = $_f;

			$c = preg_replace('/[^0-9]/','',$rule);
			$c = ($c == '') ? 1 : $c;

			$n = $_t;
			while($c > 0 ) {
				if( $dir == 1 && $c == 1 && date('l', $t) == ucfirst($d) ) {
					$s = 'today ' . date('H:i:s',$t);
				}
				$n = strtotime($s, $n);
				$c--;
			}

			return $n;
		}
	}

	private function ruleBymonth($rule, $t) {
		$_t = mktime(date('H',$t), date('i',$t), date('s',$t), $rule, date('d', $t), date('Y', $t));
		if( $t == $_t && isset($this->rules['byday']) ) {
			// TODO: this should check if one of the by*day's exists, and have a multi-day value
			return false;
		} else {
			return $_t;
		}
	}

	private function ruleBymonthday($rule, $t) {
		if( $rule < 0 ) {
			$rule = date('t', $t) + $rule + 1;
		}
		return mktime(date('H',$t), date('i',$t), date('s',$t), date('m', $t), $rule, date('Y', $t));
	}

	private function ruleByyearday($rule, $t) {
		if( $rule < 0 ) {
			$_t = $this->findEndOfPeriod();
			$d = '-';
		} else {
			$_t = $this->truncateToPeriod($t, $this->freq);
			$d = '+';
		}
		$s = $d . abs($rule -1) . ' days ' . date('H:i:s',$t);
		return strtotime($s, $_t);
	}

	private function ruleByweekno($rule, $t) {
		if( $rule < 0 ) {
			$_t = $this->findEndOfPeriod();
			$d = '-';
		} else {
			$_t = $this->truncateToPeriod($t, $this->freq);
			$d = '+';
		}

		$sub = (date('W', $_t) == 1) ? 2 : 1;
		$s = $d . abs($rule - $sub) . ' weeks ' . date('H:i:s',$t);
		$_t  = strtotime($s, $_t);

		return $_t;
	}

	private function ruleByhour($rule, $t) {
		$_t = mktime($rule, date('i',$t), date('s',$t), date('m',$t), date('d', $t), date('Y', $t));
		return $_t;
	}

	private function ruleByminute($rule, $t) {
		$_t = mktime(date('h',$t), $rule, date('s',$t), date('m',$t), date('d', $t), date('Y', $t));
		return $_t;
	}

	private function validDate( $t ) {
		if( isset($this->rules['until']) && $t > $this->rules['until'] ) {
			return false;
		}

		if (in_array($t, $this->excluded)) {
			return false;
		}

		if( isset($this->rules['bymonth']) ) {
			$months = explode(',', $this->rules['bymonth']);
			if( !in_array(date('m', $t), $months)) {
				return false;
			}
		}
		if( isset($this->rules['byday']) ) {
			$days = explode(',', $this->rules['byday']);
			foreach( $days As $i => $k ) {
				$days[$i] = $this->weekdays[ preg_replace('/[^A-Z]/', '', $k)];
			}
			if( !in_array(strtolower(date('l', $t)), $days)) {
				return false;
			}
		}
		if( isset($this->rules['byweekno']) ) {
			$weeks = explode(',', $this->rules['byweekno']);
			if( !in_array(date('W', $t), $weeks)) {
				return false;
			}
		}
		if( isset($this->rules['bymonthday'])) {
			$weekdays = explode(',', $this->rules['bymonthday']);
			foreach( $weekdays As $i => $k ) {
				if( $k < 0 ) {
					$weekdays[$i] = date('t', $t) + $k + 1;
				}
			}
			if( !in_array(date('d', $t), $weekdays)) {
				return false;
			}
		}
		if( isset($this->rules['byhour']) ) {
			$hours = explode(',', $this->rules['byhour']);
			if( !in_array(date('H', $t), $hours)) {
				return false;
			}
		}

		return true;
	}

	private function isPrerule($rule, $freq) {
		if( $rule == 'year')
			return false;
		if( $rule == 'month' && $freq == 'yearly')
			return true;
		if( $rule == 'monthday' && in_array($freq, array('yearly', 'monthly')) && !isset($this->rules['byday']))
			return true;
		// TODO: is it faster to do monthday first, and ignore day if monthday exists? - prolly by a factor of 4..
		if( $rule == 'yearday' && $freq == 'yearly' )
			return true;
		if( $rule == 'weekno' && $freq == 'yearly' )
			return true;
		if( $rule == 'day' && in_array($freq, array('yearly', 'monthly', 'weekly')))
			return true;
		if( $rule == 'hour' && in_array($freq, array('yearly', 'monthly', 'weekly', 'daily')))
			return true;
		if( $rule == 'minute' )
			return true;

		return false;
	}
}

/**
 * A class for calculating how many seconds a duration-string is
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */

class SG_iCal_Duration {
	protected $dur;

	/**
	 * Constructs a new SG_iCal_Duration from a duration-rule.
	 * The basic build-up of DURATIONs are:
	 *  (["+"] / "-") "P" (dur-date / dur-date + "T" + dur-time / dur-time / dur-week)
	 * Is solved via a really fugly reg-exp with way to many ()'s..
	 *
	 * @param $duration string
	 */
	public function __construct( $duration ) {

		$ts = 0;

		if (preg_match('/[\\+\\-]{0,1}P((\d+)W)?((\d+)D)?(T)?((\d+)H)?((\d+)M)?((\d+)S)?/', $duration, $matches) === 1) {
			$results = array(
				'weeks'=>  (int)@ $matches[2],
				'days'=>   (int)@ $matches[4],
				'hours'=>  (int)@ $matches[7],
				'minutes'=>(int)@ $matches[9],
				'seconds'=>(int)@ $matches[11]
			);

			$ts += $results['seconds'];
			$ts += 60 * $results['minutes'];
			$ts += 60 * 60 * $results['hours'];
			$ts += 24 * 60 * 60 * $results['days'];
			$ts += 7 * 24 * 60 * 60 * $results['weeks'];
		} else {
			// Invalid duration!
		}

		$dir = ($duration{0} == '-') ? -1 : 1;

		$this->dur = $dir * $ts;
	}

	/**
	 * Returns the duration in seconds
	 * @return int
	 */
	public function getDuration() {
		return $this->dur;
	}
}

/**
 * A simple Factory for converting a section/data pair into the
 * corrosponding block-object. If the section isn't known a simple
 * ArrayObject is used instead.
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal_Factory {
	/**
	 * Returns a new block-object for the section/data-pair. The list
	 * of returned objects is:
	 *
	 * vcalendar => SG_iCal_VCalendar
	 * vtimezone => SG_iCal_VTimeZone
	 * vevent => SG_iCal_VEvent
	 * * => ArrayObject
	 *
	 * @param $ical SG_iCalReader The reader this section/data-pair belongs to
	 * @param $section string
	 * @param SG_iCal_Line[]
	 */
	public static function factory( SG_iCal $ical, $section, $data ) {
		switch( $section ) {
			case "vcalendar":
				return new SG_iCal_VCalendar(SG_iCal_Line::Remove_Line($data), $ical );
			case "vtimezone":
				return new SG_iCal_VTimeZone(SG_iCal_Line::Remove_Line($data), $ical );
			case "vevent":
				return new SG_iCal_VEvent($data, $ical );

			default:
				return new ArrayObject(SG_iCal_Line::Remove_Line((array) $data) );
		}
	}
}

/**
 * A class for storing a single (complete) line of the iCal file.
 * Will find the line-type, the arguments and the data of the file and
 * store them.
 *
 * The line-type can be found by querying getIdent(), data via either
 * getData() or typecasting to a string.
 * Params can be access via the ArrayAccess. A iterator is also avilable
 * to iterator over the params.
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal_Line implements ArrayAccess, Countable, IteratorAggregate {
	protected $ident;
	protected $data;
	protected $params = array();

	protected $replacements = array('from'=>array('\\,', '\\n', '\\;', '\\:', '\\"'), 'to'=>array(',', "\n", ';', ':', '"'));

	/**
	 * Constructs a new line.
	 */
	public function __construct( $line ) {
		$split = strpos($line, ':');
		$idents = explode(';', substr($line, 0, $split));
		$ident = strtolower(array_shift($idents));

		$data = trim(substr($line, $split+1));
		$data = str_replace($this->replacements['from'], $this->replacements['to'], $data);

		$params = array();
		foreach( $idents AS $v) {
			list($k, $v) = explode('=', $v);
			$params[ strtolower($k) ] = $v;
		}

		$this->ident = $ident;
		$this->params = $params;
		$this->data = $data;
	}

	/**
	 * Is this line the begining of a new block?
	 * @return bool
	 */
	public function isBegin() {
		return $this->ident == 'begin';
	}

	/**
	 * Is this line the end of a block?
	 * @return bool
	 */
	public function isEnd() {
		return $this->ident == 'end';
	}

	/**
	 * Returns the line-type (ident) of the line
	 * @return string
	 */
	public function getIdent() {
		return $this->ident;
	}

	/**
	 * Returns the content of the line
	 * @return string
	 */
	public function getData() {
		return $this->data;
	}

	/**
	 * Returns the content of the line
	 * @return string
	 */
	public function getDataAsArray() {
		if (strpos($this->data,",") !== false) {
			return explode(",",$this->data);
		}
		else
			return array($this->data);
	}

	/**
	 * A static helper to get a array of SG_iCal_Line's, and calls
	 * getData() on each of them to lay the data "bare"..
	 *
	 * @param SG_iCal_Line[]
	 * @return array
	 */
	public static function Remove_Line($arr) {
		$rtn = array();
		foreach( $arr AS $k => $v ) {
			if(is_array($v)) {
				$rtn[$k] = self::Remove_Line($v);
			} elseif( $v instanceof SG_iCal_Line ) {
				$rtn[$k] = $v->getData();
			} else {
				$rtn[$k] = $v;
			}
		}
		return $rtn;
	}

	/**
	 * @see ArrayAccess.offsetExists
	 */
	public function offsetExists( $param ) {
		return isset($this->params[ strtolower($param) ]);
	}

	/**
	 * @see ArrayAccess.offsetGet
	 */
	public function offsetGet( $param ) {
		$index = strtolower($param);
		if (isset($this->params[ $index ])) {
			return $this->params[ $index ];
		}
	}

	/**
	 * Disabled ArrayAccess requirement
	 * @see ArrayAccess.offsetSet
	 */
	public function offsetSet( $param, $val ) {
		return false;
	}

	/**
	 * Disabled ArrayAccess requirement
	 * @see ArrayAccess.offsetUnset
	 */
	public function offsetUnset( $param ) {
		return false;
	}

	/**
	 * toString method.
	 * @see getData()
	 */
	public function __toString() {
		return $this->getData();
	}

	/**
	 * @see Countable.count
	 */
	public function count() {
		return count($this->params);
	}

	/**
	 * @see IteratorAggregate.getIterator
	 */
	public function getIterator() {
		return new ArrayIterator($this->params);
	}
}

/**
 * A collection of functions to query the events in a calendar.
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal_Query {
	/**
	 * Returns all events from the calendar between two timestamps
	 *
	 * Note that the events returned needs only slightly overlap.
	 *
	 * @param SG_iCalReader|array $ical The calendar to query
	 * @param int $start
	 * @param int $end
	 * @return SG_iCal_VEvent[]
	 */
	public static function Between( $ical, $start, $end ) {
		if( $ical instanceof SG_iCalReader ) {
			$ical = $ical->getEvents();
		}
		if( !is_array($ical) ) {
			throw new Exception('SG_iCal_Query::Between called with invalid input!');
		}

		$rtn = array();
		foreach( $ical AS $e ) {
			if( ($start <= $e->getStart() && $e->getStart() < $end)
			 || ($start < $e->getRangeEnd() && $e->getRangeEnd() <= $end) ) {
				$rtn[] = $e;
			}
		}
		return $rtn;
	}

	/**
	 * Returns all events from the calendar after a given timestamp
	 *
	 * @param SG_iCalReader|array $ical The calendar to query
	 * @param int $start
	 * @return SG_iCal_VEvent[]
	 */
	public static function After( $ical, $start ) {
		if( $ical instanceof SG_iCalReader ) {
			$ical = $ical->getEvents();
		}
		if( !is_array($ical) ) {
			throw new Exception('SG_iCal_Query::After called with invalid input!');
		}

		$rtn = array();
		foreach( $ical AS $e ) {
			if($e->getStart() >= $start || $e->getRangeEnd() >= $start) {
				$rtn[] = $e;
			}
		}
		return $rtn;
	}

	/**
	 * Sorts the events from the calendar after the specified column.
	 * Column can be all valid entires that getProperty can return.
	 * So stuff like uid, start, end, summary etc.
	 * @param SG_iCalReader|array $ical The calendar to query
	 * @param string $column
	 * @return SG_iCal_VEvent[]
	 */
	public static function Sort( $ical, $column ) {
		if( $ical instanceof SG_iCalReader ) {
			$ical = $ical->getEvents();
		}
		if( !is_array($ical) ) {
			throw new Exception('SG_iCal_Query::Sort called with invalid input!');
		}

		$cmp = create_function('$a, $b', 'return strcmp($a->getProperty("' . $column . '"), $b->getProperty("' . $column . '"));');
		usort($ical, $cmp);
		return $ical;
	}
}


/**
 * A wrapper for recurrence rules in iCalendar.  Parses the given line and puts the
 * recurrence rules in the correct field of this object.
 *
 * See http://tools.ietf.org/html/rfc2445 for more information.  Page 39 and onward contains more
 * information on the recurrence rules themselves.  Page 116 and onward contains
 * some great examples which were often used for testing.
 *
 * @package SG_iCalReader
 * @author Steven Oxley
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal_Recurrence {

	public $rrule;

	protected $freq;

	protected $until;
	protected $count;

	protected $interval;
	protected $bysecond;
	protected $byminute;
	protected $byhour;
	protected $byday;
	protected $bymonthday;
	protected $byyearday;
	protected $byyearno;
	protected $bymonth;
	protected $bysetpos;

	protected $wkst;

	/**
	 * A list of the properties that can have comma-separated lists for values.
	 * @var array
	 */
	protected $listProperties = array(
		'bysecond', 'byminute', 'byhour', 'byday', 'bymonthday',
		'byyearday', 'byyearno', 'bymonth', 'bysetpos'
	);

	/**
	 * Creates an recurrence object with a passed in line.  Parses the line.
	 * @param object $line an SG_iCal_Line object which will be parsed to get the
	 * desired information.
	 */
	public function __construct(SG_iCal_Line $line) {
		$this->parseLine($line->getData());
	}

	/**
	 * Parses an 'RRULE' line and sets the member variables of this object.
	 * Expects a string that looks like this:  'FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,TU,WE'
	 * @param string $line the line to be parsed
	 */
	protected function parseLine($line) {
		$this->rrule = $line;

		//split up the properties
		$recurProperties = explode(';', $line);
		$recur = array();

		//loop through the properties in the line and set their associated
		//member variables
		foreach ($recurProperties as $property) {
			$nameAndValue = explode('=', $property);

			//need the lower-case name for setting the member variable
			$propertyName = strtolower($nameAndValue[0]);
			$propertyValue = $nameAndValue[1];

			//split up the list of values into an array (if it's a list)
			if (in_array($propertyName, $this->listProperties)) {
				$propertyValue = explode(',', $propertyValue);
			}
			$this->$propertyName = $propertyValue;
		}
	}

	/**
	 * Set the $until member
	 * @param mixed timestamp (int) / Valid DateTime format (string)
	 */
	public function setUntil($ts) {
		if ( is_int($ts) )
			$dt = new DateTime('@'.$ts);
		else
			$dt = new DateTime($ts);
		$this->until = $dt->format('Ymd\THisO');
	}

	/**
	 * Retrieves the desired member variable and returns it (if it's set)
	 * @param string $member name of the member variable
	 * @return mixed the variable value (if set), false otherwise
	 */
	protected function getMember($member)
	{
		if (isset($this->$member)) {
			return $this->$member;
		}
		return false;
	}

	/**
	 * Returns the frequency - corresponds to FREQ in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getFreq() {
		return $this->getMember('freq');
	}

	/**
	 * Returns when the event will go until - corresponds to UNTIL in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getUntil() {
		return $this->getMember('until');
	}

	/**
	 * Returns the count of the times the event will occur (should only appear if 'until'
	 * does not appear) - corresponds to COUNT in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getCount() {
		return $this->getMember('count');
	}

	/**
	 * Returns the interval - corresponds to INTERVAL in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getInterval() {
		return $this->getMember('interval');
	}

	/**
	 * Returns the bysecond part of the event - corresponds to BYSECOND in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getBySecond() {
		return $this->getMember('bysecond');
	}

	/**
	 * Returns the byminute information for the event - corresponds to BYMINUTE in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByMinute() {
		return $this->getMember('byminute');
	}

	/**
	 * Corresponds to BYHOUR in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByHour() {
		return $this->getMember('byhour');
	}

	/**
	 *Corresponds to BYDAY in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByDay() {
		return $this->getMember('byday');
	}

	/**
	 * Corresponds to BYMONTHDAY in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByMonthDay() {
		return $this->getMember('bymonthday');
	}

	/**
	 * Corresponds to BYYEARDAY in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByYearDay() {
		return $this->getMember('byyearday');
	}

	/**
	 * Corresponds to BYYEARNO in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByYearNo() {
		return $this->getMember('byyearno');
	}

	/**
	 * Corresponds to BYMONTH in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getByMonth() {
		return $this->getMember('bymonth');
	}

	/**
	 * Corresponds to BYSETPOS in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getBySetPos() {
		return $this->getMember('bysetpos');
	}

	/**
	 * Corresponds to WKST in RFC 2445.
	 * @return mixed string if the member has been set, false otherwise
	 */
	public function getWkst() {
		return $this->getMember('wkst');
	}
}

class SG_iCal_Parser {
	/**
	 * Fetches $url and passes it on to be parsed
	 * @param string $url
	 * @param SG_iCal $ical
	 */
	public static function Parse( $url, SG_iCal $ical ) {
		$content = self::Fetch( $url );
		$content = self::UnfoldLines($content);
		self::_Parse( $content, $ical );
	}

	/**
	 * Passes a text string on to be parsed
	 * @param string $content
	 * @param SG_iCal $ical
	 */
	public static function ParseString($content, SG_iCal $ical ) {
		$content = self::UnfoldLines($content);
		self::_Parse( $content, $ical );
	}

	/**
	 * Fetches a resource and tries to make sure it's UTF8
	 * encoded
	 * @return string
	 */
	protected static function Fetch( $resource ) {
		$is_utf8 = true;

		if( is_file( $resource ) ) {
			// The resource is a local file
			$content = file_get_contents($resource);

			if( ! self::_ValidUtf8( $content ) ) {
				// The file doesn't appear to be UTF8
				$is_utf8 = false;
			}
		} else {
			// The resource isn't local, so it's assumed to
			// be a URL
			$c = curl_init();
			curl_setopt($c, CURLOPT_URL, $resource);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			if( !ini_get('safe_mode') ){
				curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
			}
			$content = curl_exec($c);

			$ct = curl_getinfo($c, CURLINFO_CONTENT_TYPE);
			$enc = preg_replace('/^.*charset=([-a-zA-Z0-9]+).*$/', '$1', $ct);
			if( $ct != '' && strtolower(str_replace('-','', $enc)) != 'utf8' ) {
				// Well, the encoding says it ain't utf-8
				$is_utf8 = false;
			} elseif( ! self::_ValidUtf8( $content ) ) {
				// The data isn't utf-8
				$is_utf8 = false;
			}
		}

		if( !$is_utf8 ) {
			$content = utf8_encode($content);
		}

		return $content;
	}

	/**
	 * Takes the string $content, and creates a array of iCal lines.
	 * This includes unfolding multi-line entries into a single line.
	 * @param $content string
	 */
	protected static function UnfoldLines($content) {
		$data = array();
		$content = explode("\n", $content);
		for( $i=0; $i < count($content); $i++) {
			$line = rtrim($content[$i]);
			while( isset($content[$i+1]) && strlen($content[$i+1]) > 0 && ($content[$i+1]{0} == ' ' || $content[$i+1]{0} == "\t" )) {
				$line .= rtrim(substr($content[++$i],1));
			}
			$data[] = $line;
		}
		return $data;
	}

	/**
	 * Parses the feed found in content and calls storeSection to store
	 * parsed data
	 * @param string $content
	 * @param SG_iCal $ical
	 */
	private static function _Parse( $content, SG_iCal $ical ) {
		$main_sections = array('vevent', 'vjournal', 'vtodo', 'vtimezone', 'vcalendar');
		$array_idents = array('exdate','rdate');
		$sections = array();
		$section = '';
		$current_data = array();

		foreach( $content AS $line ) {
			$line = new SG_iCal_Line($line);
			if( $line->isBegin() ) {
				// New block of data, $section = new block
				$section = strtolower($line->getData());
				$sections[] = strtolower($line->getData());
			} elseif( $line->isEnd() ) {
				// End of block of data ($removed = just ended block, $section = new top-block)
				$removed = array_pop($sections);
				$section = end($sections);

				if( array_search($removed, $main_sections) !== false ) {
					self::StoreSection( $removed, $current_data[$removed], $ical);
					$current_data[$removed] = array();
				}
			} else {
				// Data line
				foreach( $main_sections AS $s ) {
					// Loops though the main sections
					if( array_search($s, $sections) !== false ) {
						// This section is in the main section
						if( $section == $s ) {
							// It _is_ the main section else
							if (in_array($line->getIdent(), $array_idents))
								//exdate could appears more that once
								$current_data[$s][$line->getIdent()][] = $line;
							else {
								$current_data[$s][$line->getIdent()] = $line;
							}
						} else {
							// Sub section
							$current_data[$s][$section][$line->getIdent()] = $line;
						}
						break;
					}
				}
			}
		}
		$current_data = array();
	}

	/**
	 * Stores the data in provided SG_iCal object
	 * @param string $section eg 'vcalender', 'vevent' etc
	 * @param string $data
	 * @param SG_iCal $ical
	 */
	protected static function storeSection( $section, $data, SG_iCal $ical ) {
		$data = SG_iCal_Factory::Factory($ical, $section, $data);
		switch( $section ) {
			case 'vcalendar':
				return $ical->setCalendarInfo( $data );
			case 'vevent':
				return $ical->addEvent( $data );
			case 'vjournal':
			case 'vtodo':
				return true; // TODO: Implement
			case 'vtimezone':
				return $ical->addTimeZone( $data );
		}
	}

	/**
	 * This functions does some regexp checking to see if the value is
	 * valid UTF-8.
	 *
	 * The function is from the book "Building Scalable Web Sites" by
	 * Cal Henderson.
	 *
	 * @param string $data
	 * @return bool
	 */
	private static function _ValidUtf8( $data ) {
		$rx  = '[\xC0-\xDF]([^\x80-\xBF]|$)';
		$rx .= '|[\xE0-\xEF].{0,1}([^\x80-\xBF]|$)';
		$rx .= '|[\xF0-\xF7].{0,2}([^\x80-\xBF]|$)';
		$rx .= '|[\xF8-\xFB].{0,3}([^\x80-\xBF]|$)';
		$rx .= '|[\xFC-\xFD].{0,4}([^\x80-\xBF]|$)';
		$rx .= '|[\xFE-\xFE].{0,5}([^\x80-\xBF]|$)';
		$rx .= '|[\x00-\x7F][\x80-\xBF]';
		$rx .= '|[\xC0-\xDF].[\x80-\xBF]';
		$rx .= '|[\xE0-\xEF]..[\x80-\xBF]';
		$rx .= '|[\xF0-\xF7]...[\x80-\xBF]';
		$rx .= '|[\xF8-\xFB]....[\x80-\xBF]';
		$rx .= '|[\xFC-\xFD].....[\x80-\xBF]';
		$rx .= '|[\xFE-\xFE]......[\x80-\xBF]';
		$rx .= '|^[\x80-\xBF]';

		return ( ! (bool) preg_match('!'.$rx.'!', $data) );
	}
}



/**
 * The wrapper for vevents. Will reveal a unified and simple api for
 * the events, which include always finding a start and end (except
 * when no end or duration is given) and checking if the event is
 * blocking or similar.
 *
 * Will apply the specified timezone to timestamps if a tzid is
 * specified
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal_VEvent {
	const DEFAULT_CONFIRMED = true;

	protected $uid;

	protected $start;
	protected $end;

	protected $summary;
	protected $description;
	protected $location;

	protected $laststart;
	protected $lastend;

	public $recurrence; //RRULE
	public $recurex;    //EXRULE
	public $excluded;   //EXDATE(s)
	public $added;      //RDATE(s)

	public $freq; //getFrequency() SG_iCal_Freq

	public $data;

	/**
	 * Constructs a new SG_iCal_VEvent. Needs the SG_iCalReader
	 * supplied so it can query for timezones.
	 * @param SG_iCal_Line[] $data
	 * @param SG_iCalReader $ical
	 */
	public function __construct($data, SG_iCal $ical) {

		$this->uid = $data['uid']->getData();
		unset($data['uid']);

		if ( isset($data['rrule']) ) {
			$this->recurrence = new SG_iCal_Recurrence($data['rrule']);
			unset($data['rrule']);
		}

		if ( isset($data['exrule']) ) {
			$this->recurex = new SG_iCal_Recurrence($data['exrule']);
			unset($data['exrule']);
		}

		if( isset($data['dtstart']) ) {
			$this->start = $this->getTimestamp($data['dtstart'], $ical);
			unset($data['dtstart']);
		}

		if( isset($data['dtend']) ) {
			$this->end = $this->getTimestamp($data['dtend'], $ical);
			unset($data['dtend']);
		} elseif( isset($data['duration']) ) {
			$dur = new SG_iCal_Duration( $data['duration']->getData() );
			$this->end = $this->start + $dur->getDuration();
			unset($data['duration']);
		}

		//google cal set dtend as end of initial event (duration)
		if ( isset($this->recurrence) ) {
			//if there is a recurrence rule

			//exclusions
			if ( isset($data['exdate']) ) {
				foreach ($data['exdate'] as $exdate) {
					foreach ($exdate->getDataAsArray() as $ts) {
						$this->excluded[] = strtotime($ts);
					}
				}
				unset($data['exdate']);
			}
			//additions
			if ( isset($data['rdate']) ) {
				foreach ($data['rdate'] as $rdate) {
					foreach ($rdate->getDataAsArray() as $ts) {
						$this->added[] = strtotime($ts);
					}
				}
				unset($data['rdate']);
			}

			$until = $this->recurrence->getUntil();
			$count = $this->recurrence->getCount();
			//check if there is either 'until' or 'count' set
			if ( $until ) {
				//ok..
			} elseif ($count) {
				//if count is set, then figure out the last occurrence and set that as the end date
				$this->getFrequency();
				$until = $this->freq->lastOccurrence($this->start);
			} else {
				//forever... limit to 3 years
				$this->recurrence->setUntil('+3 years');
				$until = $this->recurrence->getUntil();
			}
			//date_default_timezone_set( xx ) needed ?;
			$this->laststart = strtotime($until);
			$this->lastend = $this->laststart + $this->getDuration();
		}

		$imports = array('summary','description','location');
		foreach( $imports AS $import ) {
			if( isset($data[$import]) ) {
				$this->$import = $data[$import]->getData();
				unset($data[$import]);
			}
		}

		if( isset($this->previous_tz) ) {
			date_default_timezone_set($this->previous_tz);
		}

		$this->data = SG_iCal_Line::Remove_Line($data);
	}


	/**
	 * Returns the Event Occurrences Iterator (if recurrence set)
	 * @return SG_iCal_Freq
	 */
	public function getFrequency() {
		if (! isset($this->freq)) {
			if ( isset($this->recurrence) ) {
				$this->freq = new SG_iCal_Freq($this->recurrence->rrule, $this->start, $this->excluded, $this->added);
			}
		}
		return $this->freq;
	}

	/**
	 * Returns the UID of the event
	 * @return string
	 */
	public function getUID() {
		return $this->uid;
	}

	/**
	 * Returns the summary (or null if none is given) of the event
	 * @return string
	 */
	public function getSummary() {
		return $this->summary;
	}

	/**
	 * Returns the description (or null if none is given) of the event
	 * @return string
	 */
	public function getDescription() {
		return $this->description;
	}

	/**
	 * Returns the location (or null if none is given) of the event
	 * @return string
	 */
	public function getLocation() {
		return $this->location;
	}

	/**
	 * Returns true if the event is blocking (ie not transparent)
	 * @return bool
	 */
	public function isBlocking() {
		return !(isset($this->data['transp']) && $this->data['transp'] == 'TRANSPARENT');
	}

	/**
	 * Returns true if the event is confirmed
	 * @return bool
	 */
	public function isConfirmed() {
		if( !isset($this->data['status']) ) {
			return self::DEFAULT_CONFIRMED;
		} else {
			return $this->data['status'] == 'CONFIRMED';
		}
	}

	/**
	 * Returns true if duration is multiple of 86400
	 * @return bool
	 */
	public function isWholeDay() {
		$dur = $this->getDuration();
		if ($dur > 0 && ($dur % 86400) == 0) {
			return true;
		}
		return false;
	}

	/**
	 * Returns the timestamp for the beginning of the event
	 * @return int
	 */
	public function getStart() {
		return $this->start;
	}

	/**
	 * Returns the timestamp for the end of the event
	 * @return int
	 */
	public function getEnd() {
		return $this->end;
	}

	/**
	 * Returns the timestamp for the end of the last event
	 * @return int
	 */
	public function getRangeEnd() {
		return max($this->end,$this->lastend);
	}

	/**
	 * Returns the duration of this event in seconds
	 * @return int
	 */
	public function getDuration() {
		return $this->end - $this->start;
	}

	/**
	 * Returns the given property of the event.
	 * @param string $prop
	 * @return string
	 */
	public function getProperty( $prop ) {
		if( isset($this->$prop) ) {
			return $this->$prop;
		} elseif( isset($this->data[$prop]) ) {
			return $this->data[$prop];
		} else {
			return null;
		}
	}



	/**
	 * Set default timezone (temporary) to get timestamps
	 * @return string
	 */
	protected function setLineTimeZone(SG_iCal_Line $line) {
		if( isset($line['tzid']) ) {
			if (!isset($this->previous_tz)) {
				$this->previous_tz = @ date_default_timezone_get();
			}
			$this->tzid = $line['tzid'];
			date_default_timezone_set($this->tzid);
			return true;
		}
		return false;
	}

	/**
	 * Calculates the timestamp from a DT line.
	 * @param $line SG_iCal_Line
	 * @return int
	 */
	protected function getTimestamp( SG_iCal_Line $line, SG_iCal $ical ) {

		if( isset($line['tzid']) ) {
			$this->setLineTimeZone($line);
			//$tz = $ical->getTimeZoneInfo($line['tzid']);
			//$offset = $tz->getOffset($ts);
			//$ts = strtotime(date('D, d M Y H:i:s', $ts) . ' ' . $offset);
		}
		$ts = strtotime($line->getData());

		return $ts;
	}
}

/**
 * The wrapper for the main vcalendar data. Used instead of ArrayObject
 * so you can easily query for title and description.
 * Exposes a iterator that will loop though all the data
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal_VCalendar implements IteratorAggregate {
	protected $data;

	/**
	 * Creates a new SG_iCal_VCalendar.
	 */
	public function __construct($data) {
		$this->data = $data;
	}

	/**
	 * Returns the title of the calendar. If no title is known, NULL
	 * will be returned
	 * @return string
	 */
	public function getTitle() {
		if( isset($this->data['x-wr-calname']) ) {
			return $this->data['x-wr-calname'];
		} else {
			return null;
		}
	}

	/**
	 * Returns the description of the calendar. If no description is
	 * known, NULL will be returned.
	 * @return string
	 */
	public function getDescription() {
		if( isset($this->data['x-wr-caldesc']) ) {
			return $this->data['x-wr-caldesc'];
		} else {
			return null;
		}
	}

	/**
	 * @see IteratorAggregate.getIterator()
	 */
	public function getIterator() {
		return new ArrayIterator($this->data);
	}
}


/**
 * The wrapper for vtimezones. Stores the timezone-id and the setup for
 * daylight savings and standard time.
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal_VTimeZone {
	protected $tzid;
	protected $daylight;
	protected $standard;
	protected $cache = array();

	/**
	 * Constructs a new SG_iCal_VTimeZone
	 */
	public function __construct( $data ) {

		$this->tzid = $data['tzid'];
		$this->daylight = $data['daylight'];
		$this->standard = $data['standard'];
	}

	/**
	 * Returns the timezone-id for this timezone. (Used to
	 * differentiate between different tzs in a calendar)
	 * @return string
	 */
	public function getTimeZoneId() {
		return $this->tzid;
	}

	/**
	 * Returns the given offset in this timezone for the given
	 * timestamp. (eg +0200)
	 * @param int $ts
	 * @return string
	 */
	public function getOffset( $ts ) {
		$act = $this->getActive($ts);
		return $this->{$act}['tzoffsetto'];
	}

	/**
	 * Returns the timezone name for the given timestamp (eg CEST)
	 * @param int $ts
	 * @return string
	 */
	public function getTimeZoneName($ts) {
		$act = $this->getActive($ts);
		return $this->{$act}['tzname'];
	}

	/**
	 * Determines which of the daylight or standard is the active
	 * setting.
	 * The call is cached for a given timestamp, so a call to
	 * getOffset and getTimeZoneName with the same ts won't calculate
	 * the answer twice.
	 * @param int $ts
	 * @return string standard|daylight
	 */
	private function getActive( $ts ) {

		if (class_exists('DateTimeZone')) {

			//PHP >= 5.2
			$tz = new DateTimeZone( $this->tzid );
			$date = new DateTime("@$ts", $tz);
			return ($date->format('I') == 1) ? 'daylight' : 'standard';

		} else {

			if( isset($this->cache[$ts]) ) {
				return $this->cache[$ts];
			}

			$daylight_freq = new SG_iCal_Freq($this->daylight['rrule'], strtotime($this->daylight['dtstart']));
			$standard_freq = new SG_iCal_Freq($this->standard['rrule'], strtotime($this->standard['dtstart']));
			$last_standard = $standard_freq->previousOccurrence($ts);
			$last_dst = $daylight_freq->previousOccurrence($ts);
			if( $last_dst > $last_standard ) {
				$this->cache[$ts] = 'daylight';
			} else {
				$this->cache[$ts] = 'standard';
			}

			return $this->cache[$ts];
		}
	}
}
