<?php

/**
 * ErrorMail - PHP error handler for reporting code errors via email
 * and/or logging them to a file for MediaWiki.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 3.0 or later
 */

$wgExtensionCredits['other'][] = array(
    'name'        => 'ErrorMail',
    'author'      => 'Vitaliy Filippov',
    'url'         => 'http://wiki.4intra.net/ErrorMail',
    'description' => 'Reports code errors via email and/or logs them to a file',
    'version'     => '2012-04-10',
);

// Send error reports to these addresses:
$wgErrorMail = array($wgEmergencyContact);

// Report ALL error levels and exceptions by default:
$wgErrorMailLevels = ~0;
$wgErrorMailExceptions = true;

// Also log reported errors into this file:
$wgErrorMailLog = '';

ErrorMail::init();

class ErrorMail
{
    static $oldHandler, $inProgress = false;

    static $errorTypes = array(
        E_ERROR             => 'E_ERROR',
        E_WARNING           => 'E_WARNING',
        E_PARSE             => 'E_PARSE',
        E_NOTICE            => 'E_NOTICE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
        E_USER_ERROR        => 'E_USER_ERROR',
        E_USER_WARNING      => 'E_USER_WARNING',
        E_USER_NOTICE       => 'E_USER_NOTICE',
        E_STRICT            => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED        => 'E_DEPRECATED',
        E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
        'exception'         => 'Exception',
    );

    static $fatalErrors = array(
        E_ERROR             => 'E_ERROR',
        E_PARSE             => 'E_PARSE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
    );

    static function init()
    {
        global $wgExceptionHooks;
        self::$oldHandler = set_error_handler(array(__CLASS__, 'handler'));
        register_shutdown_function(array(__CLASS__, 'shutdownHandler'));
        $wgExceptionHooks['MWException'][] = array(__CLASS__, 'mwExceptionHandler');
        $wgExceptionHooks['MWExceptionRaw'][] = array(__CLASS__, 'mwExceptionHandler');
    }

    /**
     * Main handler for errors ($errno = integer error level) and exceptions ($errno === 'exception')
     */
    static function handler($errno, $errstr, $errfile, $errline, $errcontext, $nostack = false)
    {
        global $wgUser, $wgServer, $wgSitename, $wgTitle, $IP;
        global $wgPasswordSender, $wgPasswordSenderName;
        global $wgSuppressCount;
        global $wgErrorMail, $wgErrorMailLog, $wgErrorMailLevels, $wgErrorMailExceptions;
        $enabled = $wgErrorMailLevels;
        $reporting = error_reporting();
        if ($reporting == 0 || isset($wgSuppressCount) && $wgSuppressCount > 0)
        {
            // Handle PHP error suppression operator (@)
            // Also handle patched wfSuppressWarnings()
            $enabled = $reporting;
        }
        if (!self::$inProgress && ($wgErrorMail || $wgErrorMailLog) &&
            ($errno == 'exception' ? $wgErrorMailExceptions : $enabled & $errno))
        {
            self::$inProgress = true;
            if (!isset(self::$errorTypes[$errno]))
                $errtype = $errno;
            else
                $errtype = self::$errorTypes[$errno];
            $username = $wgUser->getName();
            $subject = "[$wgSitename] $errtype".($wgTitle ? " at $wgTitle" : '');
            $text = date("[Y-m-d H:i:s] ").$wgSitename.', ';
            $text .= 'Title: '.($wgTitle ? $wgTitle : '-')."\n";
            $text .= "User: $username, IP: ".wfGetIP()."\n\n";
            if ($errno != 'exception')
            {
                // Exception message already includes this information
                $text .= "$errtype at $errfile:$errline\n";
            }
            $text .= trim($errstr)."\n\n";
            if (!$nostack)
            {
                $text .= "Stack trace:\n";
                $iplen = strlen($IP);
                foreach (array_slice(debug_backtrace(0), 1) as $i => $frame)
                {
                    if (substr($frame['file'], 0, $iplen+1) == $IP.'/')
                        $frame['file'] = './'.substr($frame['file'], $iplen+1);
                    $text .= "#$i: ";
                    if (isset($frame['class']))
                        $text .= $frame['class'].$frame['type'];
                    $text .= "$frame[function] at $frame[file]:$frame[line]\n";
                }
                $text .= "\n";
            }
            // Request details
            $text .= "URL: $wgServer".$_SERVER['REQUEST_URI']."\n";
            $text .= "\n\$_GET = ".var_export($_GET, true).
                "\n\$_POST = ".var_export($_POST, true).
                "\n\$_COOKIE = ".var_export($_COOKIE, true)."\n";
            if ($wgErrorMailLog)
            {
                // Log error to a file
                file_put_contents($wgErrorMailLog, $text.str_repeat('-', 80)."\n\n", FILE_APPEND);
            }
            if ($wgErrorMail)
            {
                foreach ($wgErrorMail as $to)
                {
                    UserMailer::send(
                        new MailAddress($to),
                        new MailAddress($wgPasswordSender, $wgPasswordSenderName),
                        $subject,
                        $text,
                        NULL,
                        'text/plain; charset=UTF-8'
                    );
                }
            }
            self::$inProgress = false;
        }
        if (self::$oldHandler && (error_reporting() & $errno))
        {
            $args = func_get_args();
            call_user_func_array(self::$oldHandler, $args);
        }
    }

    static function shutdownHandler()
    {
        $error = error_get_last();
        if ($error && isset(self::$fatalErrors[$error['type']]))
            self::handler($error['type'], $error['message'], $error['file'], $error['line'], NULL, true);
        return true;
    }

    static function mwExceptionHandler($error)
    {
        self::handler('exception', "$error", NULL, NULL, NULL, true);
        return true;
    }
}
