<?php

/**
        System Profile plugin for the PHP Fat-Free Framework
        Can be used with Nagios or F3's throttling feature, amongst other things.
        
        The contents of this file are subject to the terms of the GNU General
        Public License Version 3.0. You may not use this file except in
        compliance with the license. Any of the license terms and conditions
        can be waived if you get permission from the copyright holder.
        
        Copyright (c) 2010-2011 Killsaw
        Steven Bredenberg <steven@killsaw.com>
        
        Modified 2014 Fallen Komrades Development
        Malcolm White <docwyatt@fallenkomrades.id.au>

            @package SystemProfile
            @version 1.0.1
**/

//! Plugin for retrieving information about the current system.
class SystemProfile extends Prefab
{
        //! Minimum framework version required to run
        const F3_Minimum = '3.0.0';

        //! Treshold for determining if 5-minute system load average is too high.
        const OVERLOADED_THRESHOLD = 2.0;

        //@{
        //! Locale-specific error/exception messages
        const
        	TEXT_NoCygwin = 'Sorry, this class has not been tested with Cygwin.';
        //@}

        static private
                //! OS  short form
                $OS,
                //! Function mapping
                $fnMap = array(
                        'uptime' => 'echo %time% && net statistics workstation | find /i "statistics since" && wmic cpu get loadpercentage | find /i /v "LoadPercentage"',
                        'who'    => 'c:\temp\quser.exe | findstr /v /i "SESSIONNAME"'
                ),
                //! Function regexs
                $fnReg = array(
                        'uptime' => array(
                                0     => '/(?P<system_time>[^\s]+)\s+up\s+((?P<only_minutes>\d+) min|((?P<days>\d+)[^,]+,\s*)?(?P<hours>\d+):(?P<minutes>\d+)),\s*(?P<users>\d+)[^:]+:\s*(?P<load_1m>\d+(\.\d+)?)[^d]+(?P<load_5m>\d+(\.\d+)?)[^d]+(?P<load_15m>\d+(\.\d+)?)/',
                                'WIN' => '/(?P<system_time>[^\.]+)[^\n\r]+[\n\r]+Statistics since (?P<start_time>[^\n\r]+)[\n\r]+(?P<load_1m>\d+(\.\d+)?)/m'
                        ),
                        'who' => array(
                                0     => '/(?P<user>[^\s]+)\s+(?P<term>[^\s]+)\s+(?P<datetime>[^s]+\s+\d+:\d+)(\s+)?(\((?P<host>.+)\))?/',
                                'WIN' => '/\>(?P<user>.{22})(?P<term>.{18}).{24}(?P<datetime>.+)/'
                        )
                );

      	/**
      		Make the constructor private, so it can only be called by itself
      			@public
      	**/
        function __construct()
        {
                self::checkOS();                                                // OS pre-check

                $fw=Base::instance();                                           // Grab the framework instance
                $fw->set('SystemProfile', array(                                // Set the uname details &PHP version
                  			'os' => php_uname('s'),
                  			'hostname' => php_uname('n'),
                  			'release' => php_uname('r'),
                  			'version' => php_uname('v'),
                  			'machine' => php_uname('m'),
                        'php' => phpversion())
                );

                self::$OS = strtoupper(substr(PHP_OS, 0, 3));                   // Short form for OS checking
        }

        /**
        	Check that this system is not running Cygwin, as this is untested
        		@protected
        **/
        protected static function checkOS() {
              	if (preg_match('/^Cyg/', PHP_OS)) {
                    		trigger_error(self::TEXT_NoCygwin);
              	}
        }

        /**
        	Returns current system's hostname.
        		@return string
        		@public
        **/
        public static function getHostname()
        {
                return Base::instance()->get('SystemProfile.hostname');
        }
        
        /**
        	Parse and return a MS date/time string that PHP can handle
        		@return string
        		@param $datetime string  The Microsoft date time string which includes AM/PM
        		@private
        **/
        private static function parseWinTime($datetime)
        {
                $splitDateTime = explode(' ', $datetime, 3);
        
                $splitDate = explode('/', $splitDateTime[0], 3);
                foreach($splitDate as $idx => $part)
                {
                        $splitDate[$idx] = intval($part);
                }
                $splitDateTime[0] = implode('-', $splitDate);
        
                $splitTime = explode(':', $splitDateTime[1], 3);
                $splitTime[0] += ($splitDateTime[2] != "AM") ? 12 : 0;
                $splitDateTime[2] = '';
                $splitDateTime[1] = implode(':', $splitTime);
        
                return implode(' ', $splitDateTime);
        }

        /**
        	Parse and return `uptime` info. All or some.
        		@return array|string
        		@param $all bool  Return all uptime info or just actual uptime value?
        		@public
        **/
        public static function getUptime($all = false)
        {
                self::checkOS();

                $fn = 'uptime';                                                 // Set up appropriate function call
                $fn = (self::$OS == 'WIN') ? self::$fnMap[$fn] : $fn;

                if(preg_match(self::$fnReg['uptime'][(isset(self::$fnReg['uptime'][self::$OS]) || array_key_exists(self::$OS, self::$fnReg['uptime'])) ? self::$OS : 0], trim(`$fn`), $matches) == 1)
                {
                        if(self::$OS == 'WIN')                                  // Windows fudgery
                        {
                                $matches['start_time'] = self::parseWinTime($matches['start_time']);
                                $startTime = new DateTime($matches['start_time']);
                                $currTime =  new DateTime('now');

                                $dateDiff = $startTime->diff($currTime);        // All this for the Window's uptime
                                $matches['days']    = $dateDiff->days;
                                $matches['hours']   = $dateDiff->h;
                                $matches['minutes'] = $dateDiff->i;
                                
                                $matches['users']   = count(self::getOnlineUsers());
                        }

                        foreach (array('days', 'hours', 'minutes', 'only_minutes') as $idx)
                        {
                                $matches[$idx] = (isset($matches[$idx]) || array_key_exists($idx, $matches)) ? ($matches[$idx] = ($matches[$idx] == '') ? 0 : intval($matches[$idx])) : 0;
                        }

                        $matches['minutes'] = ($matches['only_minutes'] > 0) ? $matches['only_minutes'] : $matches['minutes'];

                        $data = array(
                            'system_time' => $matches['system_time'],
                            'uptime'      => sprintf("%dd %dh %dm", $matches['days'], $matches['hours'], $matches['minutes']),
                            'users'       => @intval($matches['users']),
                            'load'        => array(
                                                      1  => @floatval($matches['load_1m']),
                                                      5  => @floatval($matches['load_5m']),
                                                      15 => @floatval($matches['load_15m'])
                                             )
                        );
                        return (!$all) ? $data['uptime'] : $data;
                }
                return false;
        }

      	/**
      		Return the properties reported by PHP about the server
      			@return array
      			@public
      	**/
      	public static function getServerInfo()
        {
                self::checkOS();
                return Base::instance()->get('SystemProfile');
        }

        /**
        	Get list of logged in users.
        		@return array
        		@public
        **/
        public static function getOnlineUsers() {

              	self::checkOS();

                $fn = 'who';                                                    // Set up appropriate function call
                $fn = (self::$OS == 'WIN') ? self::$fnMap[$fn] : $fn;
                $lines = preg_split('/(\n|\r|\n\r|\r\n)/' , trim(`$fn`));
                $users = array();

                foreach($lines as $line)
                {
                        if(isset($line) && preg_match(self::$fnReg['who'][(isset(self::$fnReg['who'][self::$OS]) || array_key_exists(self::$OS, self::$fnReg['who'])) ? self::$OS : 0], $line, $matches) == 1)
                        {
                                if(self::$OS == 'WIN')                          // Windows fudgery
                                {
                                        $matches['datetime'] = self::parseWinTime(trim($matches['datetime']));
                                }
                                
                                $timeStamp = new DateTime(trim($matches['datetime']));

                                $users[] = array(
                            						'user' => trim($matches['user']),
                            						'term' => trim($matches['term']),
                            						'date' => $timeStamp->format('Y-m-d'),
                            						'time' => $timeStamp->format('H:i:s'),
                            						'host' => isset($matches['host']) ? trim($matches['host']) : null
                    						);
                        }
                } 
                return $users;               
        }

        /**
        	Get system load levels (1, 5, and 15 minute load avgs)
        		@return array
        		@public
        **/
        public static function getLoadLevels() {
        
              	self::checkOS();
                
                $info = self::getUptime(true);
                return ($info !== false) ? $info['load'] : false;
        }
        
        /**
        	Check if system load is too high.
        		@param $check string
        		@return bool
        		@public
        **/
        public static function systemIsOkay($check = 5) {

                $check = (self::$OS == 'WIN') ? 1 : $check;                     // Windows kludge, again
                $load = self::getLoadLevels();
                
                return ($load !== false) ? (($load[$check] >= self::OVERLOADED_THRESHOLD) ? false : true) : "Unknown";
        }                
}
