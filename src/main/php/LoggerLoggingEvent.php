<?php
/**
 * Licensed to the Apache Software Foundation (ASF) under one or more
 * contributor license agreements. See the NOTICE file distributed with
 * this work for additional information regarding copyright ownership.
 * The ASF licenses this file to You under the Apache License, Version 2.0
 * (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 *
 *	   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package log4php
 */

/**
 * The internal representation of logging event.
 *
 * @version $Revision$
 * @package log4php
 */
class LoggerLoggingEvent {

	private static $startTime;

	/** 
	* @var string Fully Qualified Class Name of the calling category class.
	*/
	private $fqcn;
	
	/**
	* @var Logger reference
	*/
	private $logger;
	
	/** 
	 * The category (logger) name.
	 * This field will be marked as private in future
	 * releases. Please do not access it directly. 
	 * Use the {@link getLoggerName()} method instead.
	 * @deprecated 
	 */
	private $categoryName;
	
	/** 
	 * Level of the logging event.
	 * @var LoggerLevel
	 */
	protected $level;
	
	/** 
	 * The nested diagnostic context (NDC) of logging event.
	 * @var string  
	 */
	private $ndc;
	
	/** 
	 * Have we tried to do an NDC lookup? If we did, there is no need
	 * to do it again.	Note that its value is always false when
	 * serialized. Thus, a receiving SocketNode will never use it's own
	 * (incorrect) NDC. See also writeObject method.
	 * @var boolean
	 */
	private $ndcLookupRequired = true;
	
	/** 
	 * @var mixed The application supplied message of logging event. 
	 */
	private $message;
	
	/** 
	 * The application supplied message rendered through the log4php
	 * objet rendering mechanism. At present renderedMessage == message.
	 * @var string
	 */
	private $renderedMessage;
	
	/** 
	 * The name of thread in which this logging event was generated.
	 * log4php saves here the process id via {@link PHP_MANUAL#getmypid getmypid()} 
	 * @var mixed
	 */
	private $threadName;
	
	/** 
	* The number of seconds elapsed from 1/1/1970 until logging event
	* was created plus microseconds if available.
	* @var float
	*/
	public $timeStamp;
	
	/** 
	* @var LoggerLocationInfo Location information for the caller. 
	*/
	private $locationInfo;
	
	/**
	 * @var LoggerThrowableInformation log4php internal representation of throwable
	 */
	private $throwableInfo;
	
	/**
	* Instantiate a LoggingEvent from the supplied parameters.
	*
	* Except {@link $timeStamp} all the other fields of
	* LoggerLoggingEvent are filled when actually needed.
	*
	* @param string $fqcn name of the caller class.
	* @param mixed $logger The {@link Logger} category of this event or the logger name.
	* @param LoggerLevel $level The level of this event.
	* @param mixed $message The message of this event.
	* @param integer $timeStamp the timestamp of this logging event.
	* @param Exception $throwable The throwable associated with logging event
	*/
	public function __construct($fqcn, $logger, LoggerLevel $level, $message, $timeStamp = null, $throwable = null) {
		$this->fqcn = $fqcn;
		if($logger instanceof Logger) {
			$this->logger = $logger;
			$this->categoryName = $logger->getName();
		} else {
			$this->categoryName = strval($logger);
		}
		$this->level = $level;
		$this->message = $message;
		if($timeStamp !== null && is_numeric($timeStamp)) {
			$this->timeStamp = $timeStamp;
		} else {
			$this->timeStamp = microtime(true);
		}
		
		if ($throwable !== null && $throwable instanceof Exception) {
			$this->throwableInfo = new LoggerThrowableInformation($throwable);
		}
	}

	/**
	 * Returns the full qualified classname.
	 * TODO: PHP does contain namespaces in 5.3. Those should be returned too, 
	 */
	 public function getFullQualifiedClassname() {
		 return $this->fqcn;
	 }

	private static $ignoreFileRegex;
	private static $ignorePathRegex = "//";

	/**
	 * Set filter for use by getLocationInformation() to ignore log4php caller wrapper functions.
	 */
	public static function setIgnoreFileRegex($regex) {
		self::$ignoreFileRegex = $regex; // e.g. '/\/(my-log4php-wrapper.php|myOtherLog4PhpWrapper.php)$/'
	}

	/**
	 * Set filter for use by getLocationInformation() to ignore a common file prefix, or ignore entire path.
	 */
	public static function setIgnorePathRegex($regex) {
		self::$ignorePathRegex = $regex; // e.g. '/^.*\/(ignore everything before this folder\/)/' or ignore entire path '/^.*\/([^\/]+)/'
	}

	/**
	 * Set the location information for this logging event. The collected
	 * information is cached for future use.
	 *
	 * <p>This method uses {@link PHP_MANUAL#debug_backtrace debug_backtrace()} function (if exists)
	 * to collect informations about caller.</p>
	 * <p>It only recognize informations generated by {@link Logger} and its subclasses.</p>
	 * <p>Use setIgnoreFileRegex() to skip file(s) of caller using own log4php wrapper class/function.</p>
	 * <p>Use setIgnorePathRegex() to shorten reported file path by removing a common prefix.</p>
	 * @return LoggerLocationInfo
	 */
	public function getLocationInformation() {
		if($this->locationInfo === null) {

			$locationInfo = array();
			$trace = debug_backtrace();
			$prevHop = null;
			// make a downsearch to identify the caller
			$hop = array_pop($trace);
			while($hop !== null) {
				if(isset($hop['class'])) {
					// Note: This next if() statement inspired by the following stackoverflow question:
					// http://stackoverflow.com/questions/15219327/php-log4php-dynamically-set-fileline-column
					if (isset($hop['file']) and isset(self::$ignoreFileRegex)) {
						if(1 === preg_match(self::$ignoreFileRegex, $hop['file'])) {
							// come here if detected a caller wrapper for log4php
							$locationInfo['line'] =                                            $prevHop['line'] ;
							$locationInfo['file'] = preg_replace(self::$ignorePathRegex, "$1", $prevHop['file']); // replace annoyingly verbose path prefix
							break;
						}
					}
					// we are sometimes in functions = no class available: avoid php warning here
					$className = strtolower($hop['class']);
					if(!empty($className) and ($className == 'logger' or strtolower(get_parent_class($className)) == 'logger')) {
						$locationInfo['line'] =                                            $hop['line'] ;
						$locationInfo['file'] = preg_replace(self::$ignorePathRegex, "$1", $hop['file']); // replace annoyingly verbose path prefix
						break;
					}
				}
				$prevHop = $hop;
				$hop = array_pop($trace);
			}
			$locationInfo['class'] = isset($prevHop['class']) ? $prevHop['class'] : 'main';
			if(isset($prevHop['function']) and
				$prevHop['function'] !== 'include' and
				$prevHop['function'] !== 'include_once' and
				$prevHop['function'] !== 'require' and
				$prevHop['function'] !== 'require_once') {

				$locationInfo['function'] = $prevHop['function'];
			} else {
				$locationInfo['function'] = 'main';
			}
					 
			$this->locationInfo = new LoggerLocationInfo($locationInfo, $this->fqcn);
		}
		return $this->locationInfo;
	}

	/**
	 * Return the level of this event. Use this form instead of directly
	 * accessing the {@link $level} field.
	 * @return LoggerLevel	
	 */
	public function getLevel() {
		return $this->level;
	}

	/**
	 * Returns the logger which created the event.
	 * @return Logger
	 */
	public function getLogger() {
		return $this->logger;
	}
	
	/**
	 * Return the name of the logger. Use this form instead of directly
	 * accessing the {@link $categoryName} field.
	 * @return string  
	 */
	public function getLoggerName() {
		return $this->categoryName;
	}

	/**
	 * Return the message for this logging event.
	 * @return mixed
	 */
	public function getMessage() {
		return $this->message;
	}

	/**
	 * This method returns the NDC for this event. It will return the
	 * correct content even if the event was generated in a different
	 * thread or even on a different machine. The {@link LoggerNDC::get()} method
	 * should <b>never</b> be called directly.
	 * @return string  
	 */
	public function getNDC() {
		if($this->ndcLookupRequired) {
			$this->ndcLookupRequired = false;
			$this->ndc = LoggerNDC::get();
		}
		return $this->ndc;
	}

	/**
	 * Returns the the context corresponding to the <code>key</code>
	 * parameter.
	 * @return string
	 */
	public function getMDC($key) {
		return LoggerMDC::get($key);
	}
	
	/**
	 * Returns the entire MDC context.
	 * @return array
	 */
	public function getMDCMap () {
		return LoggerMDC::getMap();
	}

	/**
	 * Render message.
	 * @return string
	 */
	public function getRenderedMessage() {
		if($this->renderedMessage === null and $this->message !== null) {
			if(is_string($this->message)) {
				$this->renderedMessage = $this->message;
			} else {
				$rendererMap = Logger::getHierarchy()->getRendererMap();
				$this->renderedMessage= $rendererMap->findAndRender($this->message);
			}
		}
		return $this->renderedMessage;
	}

	/**
	 * Returns the time when the application started, as a UNIX timestamp 
	 * with microseconds.
	 * @return float
	 */
	public static function getStartTime() {
		if(!isset(self::$startTime)) {
			self::$startTime = microtime(true);
		}
		return self::$startTime; 
	}

	/**
	 * @return float
	 */
	public function getTimeStamp() {
		return $this->timeStamp;
	}
	
	/**
	 * Returns the time in seconds passed from the beginning of execution to 
	 * the time the event was constructed.
	 * 
	 * @return float Seconds with microseconds in decimals.
	 */
	public function getRelativeTime() {
		return $this->timeStamp - self::$startTime;
	}
	
	/**
	 * Returns the time in milliseconds passed from the beginning of execution
	 * to the time the event was constructed.
	 * 
	 * @deprecated This method has been replaced by getRelativeTime which 
	 * 		does not perform unneccesary multiplication and formatting.
	 * 
	 * @return integer 
	 */
	public function getTime() {
		$eventTime = $this->getTimeStamp();
		$eventStartTime = LoggerLoggingEvent::getStartTime();
		return number_format(($eventTime - $eventStartTime) * 1000, 0, '', '');
	}
	
	/**
	 * @return mixed
	 */
	public function getThreadName() {
		if ($this->threadName === null) {
			$this->threadName = (string)getmypid();
		}
		return $this->threadName;
	}

	/**
	 * @return mixed LoggerThrowableInformation
	 */
	public function getThrowableInformation() {
		return $this->throwableInfo;
	}
	
	/**
	 * Serialize this object
	 * @return string
	 */
	public function toString() {
		serialize($this);
	}
	
	/**
	 * Avoid serialization of the {@link $logger} object
	 */
	public function __sleep() {
		return array(
			'fqcn',
			'categoryName',
			'level',
			'ndc',
			'ndcLookupRequired',
			'message',
			'renderedMessage',
			'threadName',
			'timeStamp',
			'locationInfo',
		);
	}

}

LoggerLoggingEvent::getStartTime();
