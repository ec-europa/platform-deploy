<?php
/**
  * iCalcreator, the PHP class package managing iCal (rfc2445/rfc5445) calendar information.
 *
 * copyright (c) 2007-2019 Kjell-Inge Gustafsson, kigkonsult, All rights reserved
 * Link      https://kigkonsult.se
 * Package   iCalcreator
 * Version   2.29.14
 * License   Subject matter of licence is the software iCalcreator.
 *           The above copyright, link, package and version notices,
 *           this licence notice and the invariant [rfc5545] PRODID result use
 *           as implemented and invoked in iCalcreator shall be included in
 *           all copies or substantial portions of the iCalcreator.
 *
 *           iCalcreator is free software: you can redistribute it and/or modify
 *           it under the terms of the GNU Lesser General Public License as published
 *           by the Free Software Foundation, either version 3 of the License,
 *           or (at your option) any later version.
 *
 *           iCalcreator is distributed in the hope that it will be useful,
 *           but WITHOUT ANY WARRANTY; without even the implied warranty of
 *           MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *           GNU Lesser General Public License for more details.
 *
 *           You should have received a copy of the GNU Lesser General Public License
 *           along with iCalcreator. If not, see <https://www.gnu.org/licenses/>.
 *
 * This file is a part of iCalcreator.
*/

namespace Kigkonsult\Icalcreator\Util;

use DateTime;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use Kigkonsult\Icalcreator\Vcalendar;

use function array_change_key_case;
use function array_keys;
use function checkdate;
use function count;
use function ctype_alpha;
use function ctype_digit;
use function date;
use function end;
use function explode;
use function get_class;
use function implode;
use function in_array;
use function is_array;
use function is_null;
use function mktime;
use function sprintf;
use function strcasecmp;
use function strlen;
use function strtoupper;
use function substr;
use function trim;
use function usort;
use function var_export;

/**
 * iCalcreator recur support class
 *
 * @author Kjell-Inge Gustafsson, kigkonsult <ical@kigkonsult.se>
 * @since  2.27.16 - 2019-03-08
 */
class RecurFactory
{
    /**
     * @const int  in recur2date, years to extend startYear to create an endDate, if missing
     */
    const EXTENDYEAR = 2;

    /**
     * @var string  iCal date/time key values ( week, tz used in test)
     * @static
     */
    public static $LCYEAR  = 'year';
    public static $LCMONTH = 'month';
    public static $LCWEEK  = 'week';
    public static $LCDAY   = 'day';
    public static $LCHOUR  = 'hour';
    public static $LCMIN   = 'min';
    public static $LCSEC   = 'sec';
    public static $LCtz    = 'tz';

    /**
     * Static values for recur BYDAY
     *
     * @var array
     * @access private
     * @static
     */
    private static $DAYNAMES = [
        Vcalendar::SU,
        Vcalendar::MO,
        Vcalendar::TU,
        Vcalendar::WE,
        Vcalendar::TH,
        Vcalendar::FR,
        Vcalendar::SA
    ];

    /*
     * @var array   troublesome simple recurs keys
     * @access private
     * @static
     */
    private static $RECURBYX  = [
        Vcalendar::BYSECOND,
        Vcalendar::BYMINUTE,
        Vcalendar::BYHOUR,
        Vcalendar::BYMONTHDAY,
        Vcalendar::BYYEARDAY,
        Vcalendar::BYWEEKNO,
        Vcalendar::BYSETPOS,
        Vcalendar::WKST
    ];

    /*
     * @var string  DateTime format keys
     * @access private
     * @static
     */
    private static $YMD  = 'Ymd';
    private static $LCD  = 'd'; // day NN
    private static $LCJ  = 'j'; // day [N]N
    private static $LCM  = 'm'; // month
    private static $LCT  = 't'; // number of days in month
    private static $UCY  = 'Y'; // year NNNN
    private static $LCW  = 'w'; // day of week number
    private static $UCW  = 'W'; // week number
    private static $YMDs = '%04d%02d%02d';
    private static $HIS  = '%02d%02d%02d';

    /*
     * @var string  DateTime nodify string
     * @access private
     * @static
     */
    private static $FMTX = '%d days';

    /*
     * @var string  fullRecur2date keys
     * @access private
     * @static
     */
    private static $YEARCNT_UP      = 'yearcnt_up';
    private static $YEARCNT_DOWN    = 'yearcnt_down';
    private static $MONTHDAYNO_UP   = 'monthdayno_up';
    private static $MONTHDAYNO_DOWN = 'monthdayno_down';
    private static $MONTHCNT_DOWN   = 'monthcnt_down';
    private static $YEARDAYNO_UP    = 'yeardayno_up';
    private static $YEARDAYNO_DOWN  = 'yeardayno_down';
    private static $WEEKNO_UP       = 'weekno_up';
    private static $WEEKNO_DOWN     = 'weekno_down';

    /**
     * Sort recur dates
     *
     * @param string $byDayA
     * @param string $byDayB
     * @return int
     * @access private
     * @static
     */
    private static function recurBydaySort( $byDayA, $byDayB ) {
        static $days = [
            Vcalendar::SU => 0,
            Vcalendar::MO => 1,
            Vcalendar::TU => 2,
            Vcalendar::WE => 3,
            Vcalendar::TH => 4,
            Vcalendar::FR => 5,
            Vcalendar::SA => 6,
        ];
        return ( $days[substr( $byDayA, -2 )] < $days[substr( $byDayB, -2 )] ) ? -1 : 1;
    }

    /**
     * Return formatted output for calendar component property data value type recur
     *
     * "The value of the UNTIL rule part MUST have the same value type as the "DTSTART" property.
     *  Furthermore, if the "DTSTART" property is specified as a date with local time,
     *    then the UNTIL rule part MUST also be specified as a date with local time.
     *  If the "DTSTART" property is specified as a date
     *      with UTC time
     *      or
     *      a date with local time and time zone reference,
     *    then the UNTIL rule part MUST be specified as a date with UTC time.
     *  In the case of the "STANDARD" and "DAYLIGHT" sub-components
     *    the UNTIL rule part MUST always be specified as a date with UTC time.
     *  If specified as a DATE-TIME value, then it MUST be specified in a UTC time format."
     * @param string $recurProperty
     * @param array  $recurData
     * @param bool   $allowEmpty
     * @return string
     * @static
     * @since 2.29.6 2019-06-23
     * @todo above
     */
    public static function formatRecur( $recurProperty, $recurData, $allowEmpty ) {
        static $FMTFREQEQ        = 'FREQ=%s';
        static $FMTDEFAULTEQ     = ';%s=%s';
        static $FMTOTHEREQ       = ';%s=';
        static $RECURBYDAYSORTER = null;
        if( is_null( $RECURBYDAYSORTER )) {
            $RECURBYDAYSORTER    = [ get_class(), 'recurBydaySort' ];
        }
        if( empty( $recurData )) {
            return null;
        }
        $output = null;
        if( empty( $recurData[Util::$LCvalue] ) ) {
            return ( $allowEmpty ) ? StringFactory::createElement( $recurProperty ) : null;
        }
        $isValueDate = ParameterFactory::isParamsValueSet( $recurData, Vcalendar::DATE );
        if( isset( $recurData[Util::$LCparams] )) {
            ParameterFactory::ifExistRemove( $recurData[Util::$LCparams], Vcalendar::VALUE );
            $attributes = ParameterFactory::createParams( $recurData[Util::$LCparams] );
        }
        else {
            $attributes = null;
        }
        $content1   = $content2 = null;
        foreach( $recurData[Util::$LCvalue] as $ruleLabel => $ruleValue ) {
            $ruleLabel = strtoupper( $ruleLabel );
            switch( $ruleLabel ) {
                case Vcalendar::FREQ :
                    $content1 .= sprintf( $FMTFREQEQ, $ruleValue );
                    break;
                case Vcalendar::UNTIL :
                    $content2  .= sprintf(
                        $FMTDEFAULTEQ, Vcalendar::UNTIL, DateTimeFactory::dateTime2Str( $ruleValue, $isValueDate )
                    );
                    break;
                case Vcalendar::COUNT :
                case Vcalendar::INTERVAL :
                case Vcalendar::WKST :
                    $content2 .= sprintf( $FMTDEFAULTEQ, $ruleLabel, $ruleValue );
                    break;
                case Vcalendar::BYDAY :
                    $byday = [ Util::$SP0 ];
                    $bx    = 0;
                    foreach( $ruleValue as $bix => $bydayPart ) {
                        if( ! empty( $byday[$bx] ) &&   // new day
                            ! ctype_digit( substr( $byday[$bx], -1 ) ) ) {
                            $byday[++$bx] = Util::$SP0;
                        }
                        if( ! is_array( $bydayPart )) {  // day without order number
                            $byday[$bx] .= (string) $bydayPart;
                        }
                        else {                          // day with order number
                            foreach( $bydayPart as $bix2 => $bydayPart2 ) {
                                $byday[$bx] .= (string) $bydayPart2;
                            }
                        }
                    } // end foreach( $ruleValue as $bix => $bydayPart )
                    if( 1 < count( $byday ) ) {
                        usort( $byday, $RECURBYDAYSORTER );
                    }
                    $content2 .= sprintf( $FMTDEFAULTEQ, Vcalendar::BYDAY, implode( Util::$COMMA, $byday ) );
                    break;
                default : // BYSECOND/BYMINUTE/BYHOUR/BYMONTHDAY/BYYEARDAY/BYWEEKNO/BYMONTH/BYSETPOS...
                    if( is_array( $ruleValue ) ) {
                        $content2 .= sprintf( $FMTOTHEREQ, $ruleLabel );
                        $content2 .= implode( Util::$COMMA, $ruleValue );
                    }
                    else {
                        $content2 .= sprintf( $FMTDEFAULTEQ, $ruleLabel, $ruleValue );
                    }
                    break;
            } // end switch( $ruleLabel )
        } // end foreach( $theRule[Util::$LCvalue] )) as $ruleLabel => $ruleValue )
        $output .= StringFactory::createElement( $recurProperty, $attributes, $content1 . $content2 );
        return $output;
    }

    /**
     * Return (array) parsed rexrule string
     *
     * @param string $row
     * @return array
     * @static
     * @since 2.27.3 - 2018-12-28
     */
    public static function parseRexrule( $row ) {
        static $EQ = '=';
        $recur     = [];
        $values    = explode( Util::$SEMIC, $row );
        foreach( $values as $value2 ) {
            if( empty( $value2 )) {
                continue;
            } // ;-char in end position ???
            $value3    = explode( $EQ, $value2, 2 );
            $ruleLabel = strtoupper( $value3[0] );
            switch( $ruleLabel ) {
                case Vcalendar::BYDAY:
                    $value4 = explode( Util::$COMMA, $value3[1] );
                    if( 1 < count( $value4 )) {
                        foreach( $value4 as $v5ix => $value5 ) {
                            $value4[$v5ix] = self::updateDayNoAndDayName( trim((string) $value5 ) );
                        }
                    }
                    else {
                        $value4 = self::updateDayNoAndDayName( trim((string) $value3[1] ) );
                    }
                    $recur[$ruleLabel] = $value4;
                    break;
                default:
                    $value4 = explode( Util::$COMMA, $value3[1] );
                    if( 1 < count( $value4 )) {
                        $value3[1] = $value4;
                    }
                    $recur[$ruleLabel] = $value3[1];
                    break;
            } // end - switch $ruleLabel
        } // end - foreach( $values.. .
        return $recur;
    }

    /*
     * Updates day number and/or day name
     *
     * @param string $dayValueBase
     * @return array
     * @static
     * @since  2.27.16 - 2019-03-03
     */
    private static function updateDayNoAndDayName( $dayValueBase ) {
        $dayno  = $dayName = false;
        if(( ctype_alpha( substr( $dayValueBase, -1 ))) &&
            ( ctype_alpha( substr( $dayValueBase, -2, 1 )))) {
            $dayName = substr( $dayValueBase, -2, 2 );
            if( 2 < strlen( $dayValueBase )) {
                $dayno = substr( $dayValueBase, 0, ( strlen( $dayValueBase ) - 2 ));
            }
        }
        if( false !== $dayno ) {
            $output[] = $dayno;
        }
        if( false !== $dayName ) {
            $output[Vcalendar::DAY] = $dayName;
        }
        return $output;
    }

    /**
     * Convert input format for EXRULE and RRULE to internal format
     *
     * "The value of the UNTIL rule part MUST have the same value type as the "DTSTART" property."
     * "If specified as a DATE-TIME value, then it MUST be specified in a UTC time format."
     * @param array $rexrule
     * @param array $params    merged with dtstart params
     * @return array
     * @throws Exception
     * @throws InvalidArgumentException
     * @static
     * @since 2.29.2 2019-09-02
     * @todo "The BYSECOND, BYMINUTE and BYHOUR rule parts MUST NOT be specified
     *        when the associated "DTSTART" property has a DATE value type."
     */
    public static function setRexrule( $rexrule, array $params ) {
        static $ERR    = 'Invalid input date \'%s\'';
        static $RKEYS1 = [
            Vcalendar::FREQ, Vcalendar::UNTIL, Vcalendar::COUNT, Vcalendar::INTERVAL,
            Vcalendar::BYSECOND, Vcalendar::BYHOUR, Vcalendar::BYHOUR
        ];
        static $RKEYS2 = [
            Vcalendar::BYMONTHDAY, Vcalendar::BYYEARDAY, Vcalendar::BYWEEKNO,
            Vcalendar::BYMONTH, Vcalendar::BYSETPOS, Vcalendar::WKST
        ];
        $input  = [];
        if( empty( $rexrule )) {
            return $input;
        }
        $params      = [Util::$LCparams => $params];
        $isValueDate = ParameterFactory::isParamsValueSet( $params, Vcalendar::DATE );
        $paramTZid   = ParameterFactory::getParamTzid( $params );
        $rexrule     = array_change_key_case( $rexrule, CASE_UPPER );
        foreach( $rexrule as $ruleLabel => $ruleValue ) {
            switch( true ) {
                case ( Vcalendar::UNTIL != $ruleLabel ) :
                    $input[$ruleLabel] = $ruleValue;
                    break;
                case ( $ruleValue instanceof DateTimeInterface ) :
                    $ruleValue = DateTimeFactory::cnvrtDateTimeInterface( $ruleValue );
                    $input[$ruleLabel] = DateTimeFactory::setDateTimeTimeZone( $ruleValue, Vcalendar::UTC );
                    ParameterFactory::ifExistRemove( $params[Util::$LCparams], Vcalendar::TZID );
                    break;
                case ( DateTimeFactory::isStringAndDate( $ruleValue )) :
                    list( $dateStr, $timezonePart ) = DateTimeFactory::splitIntoDateStrAndTimezone( $ruleValue );
                    $isLocalTime = ( empty( $timezonePart ) && empty( $paramTZid ));
                    $dateTime = DateTimeFactory::getDateTimeWithTimezoneFromString(
                        $dateStr,
                        $isLocalTime ? null : $timezonePart,
                        $isLocalTime ? Vcalendar::UTC : $paramTZid,
                        true
                    );
                    if( ! $isValueDate ) {
                        $dateTime = DateTimeFactory::setDateTimeTimeZone( $dateTime, Vcalendar::UTC );
                        ParameterFactory::ifExistRemove( $params[Util::$LCparams], Vcalendar::TZID );
                    }
                    $input[$ruleLabel] = $dateTime;
                    break;
                default :
                    throw new InvalidArgumentException( sprintf( $ERR, var_export( $ruleValue, true )));
                    break;
            } // end switch
        } // end foreach( $rexrule as $ruleLabel => $ruleValue )
        /* set recurrence rule specification in rfc2445 order */
        $output = [];
        foreach( $RKEYS1 as $rKey1 ) {
            if( isset( $input[$rKey1] )) {
                $output[$rKey1] = $input[$rKey1];
            }
        }
        if( isset( $input[Vcalendar::BYDAY] )) {
            if( ! is_array( $input[Vcalendar::BYDAY] )) { // ensure upper case.. .
                $output[Vcalendar::BYDAY] = strtoupper( $input[Vcalendar::BYDAY] );
            }
            else {
                foreach( $input[Vcalendar::BYDAY] as $BYDAYx => $BYDAYv ) {
                    if( 0 == strcasecmp( Vcalendar::DAY, $BYDAYx )) {
                        $output[Vcalendar::BYDAY][Vcalendar::DAY] = strtoupper( $BYDAYv );
                    }
                    elseif( ! is_array( $BYDAYv )) {
                        $output[Vcalendar::BYDAY][$BYDAYx] = $BYDAYv;
                    }
                    else {
                        foreach( $BYDAYv as $BYDAYx2 => $BYDAYv2 ) {
                            if( 0 == strcasecmp( Vcalendar::DAY, $BYDAYx2 )) {
                                $output[Vcalendar::BYDAY][$BYDAYx][Vcalendar::DAY] = strtoupper( $BYDAYv2 );
                            }
                            else {
                                $output[Vcalendar::BYDAY][$BYDAYx][$BYDAYx2] = $BYDAYv2;
                            }
                        }
                    }
                }
            }
        } // end if( isset( $input[Vcalendar::BYDAY] ))
        foreach( $RKEYS2 as $rKey2 ) {
            if( isset( $input[$rKey2] )) {
                $output[$rKey2] = $input[$rKey2];
            }
        }
        return [ Util::$LCvalue => $output ] + $params;
    }

    /**
     * Update array $result with dates based on a recur pattern
     *
     * If missing, UNTIL is set 1 year from startdate (emergency break)
     *
     * @param array           $result      array to update, array([Y-m-d] => bool)
     * @param array           $recur       pattern for recurrency (only value part, params ignored)
     * @param string|DateTime $wDateIn     component start date
     * @param string|DateTime $fcnStartIn  start date
     * @param string|DateTime $fcnEndIn    end date
     * @throws Exception
     * @static
     * @since  2.27.16 - 2019-03-08
     * @todo   BYHOUR, BYMINUTE, BYSECOND, WEEKLY at year end/start OR not at all
     */
    public static function recur2date( & $result, $recur, $wDateIn, $fcnStartIn, $fcnEndIn = null ) {
        if( ! isset( $recur[Vcalendar::FREQ] )) { // "MUST be specified.. ." ??
            $recur[Vcalendar::FREQ] = Vcalendar::DAILY;
        }
        if( ! isset( $recur[Vcalendar::INTERVAL] )) {
            $recur[Vcalendar::INTERVAL] = 1;
        }
        switch( true ) {
            case( self::isSimpleDailyRecur( $recur ) ) :
                $result = $result + self::recurDailySimple( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
                ksort( $result, SORT_NUMERIC );
                break;
            case( self::isSimpleMonthlyRecur1( $recur ) ) :
                $result = $result + self::recurMonthlySimple1( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
                ksort( $result, SORT_NUMERIC );
                break;
            case( self::isSimpleWeeklyRecur1( $recur ) ) :
                $result = $result + self::recurWeeklySimple1( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
                ksort( $result, SORT_NUMERIC );
                break;
            case( self::isSimpleWeeklyRecur2( $recur ) ) :
                $result = $result + self::recurWeeklySimple2( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
                ksort( $result, SORT_NUMERIC );
                break;
            case( self::isSimpleYearlyRecur1( $recur ) ) :
                $result = $result + self::recurYearlySimple1( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
                ksort( $result, SORT_NUMERIC );
                break;
            default :
                self::fullRecur2date( $result, $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
        }
    }

    /*
     *  Return Bool true if it is an simple DAILY recur
     *
     * @param array $recur
     * @return bool
     * @static
     * @since  2.27.16 - 2019-03-07
     */
    public static function isSimpleDailyRecur( array $recur ) {
        if( Vcalendar::DAILY != $recur[Vcalendar::FREQ ] ) {
            return false;
        }
        foreach( self::$RECURBYX as $byX ) {
            if( Vcalendar::BYMONTHDAY == $byX ) {
                continue;
            }
            if( isset( $recur[$byX])) {
                return false;
            }
        }
        if( ! isset( $recur[Vcalendar::BYDAY] )) {
            return true;
        }
        if( empty( self::getRecurByDaysWithNoEnumeratedWeekdays( $recur[Vcalendar::BYDAY] ))) {
            return false;
        }
        return true;
    }

    /*
     *  Return Bool true if it is an simple WEEKLY recur without BYDAYs
     *
     * @param array $recur
     * @return bool
     * @static
     * @since  2.27.16 - 2019-03-03
     */
    public static function isSimpleWeeklyRecur1( array $recur ) {
        if( Vcalendar::WEEKLY != $recur[Vcalendar::FREQ ] ) {
            return false;
        }
        foreach( self::$RECURBYX as $byX ) {
            if( isset( $recur[$byX])) {
                return false;
            }
        }
        if( isset( $recur[Vcalendar::BYDAY] )) {
            return false;
        }
        return true;
    }

    /*
     *  Return Bool true if it is an simple WEEKLY recur with BYDAYs
     *
     * @param array $recur
     * @return bool
     * @static
     * @since  2.27.16 - 2019-03-03
     */
    public static function isSimpleWeeklyRecur2( array $recur ) {
        if( Vcalendar::WEEKLY != $recur[Vcalendar::FREQ ] ) {
            return false;
        }
        foreach( self::$RECURBYX as $byX ) {
            if( isset( $recur[$byX])) {
                return false;
            }
        }
        if( ! isset( $recur[Vcalendar::BYDAY] )) {
            return false;
        }
        if( empty( self::getRecurByDaysWithNoEnumeratedWeekdays( $recur[Vcalendar::BYDAY] ))) {
            return false;
        }
        return true;
    }

    /*
     *  Return Bool true if it is an simple MONTHLY recur with BYMONTHDAY but without BYDAYs
     *
     * @param array $recur
     * @return bool
     * @since  2.27.16 - 2019-03-03
     */
    public static function isSimpleMonthlyRecur1( array $recur ) {
        if( Vcalendar::MONTHLY != $recur[Vcalendar::FREQ ] ) {
            return false;
        }
        foreach( self::$RECURBYX as $byX ) {
            if( Vcalendar::BYMONTHDAY == $byX ) {
                continue;
            }
            if( isset( $recur[$byX])) {
                return false;
            }
        }
        if( isset( $recur[Vcalendar::BYDAY] )) {
            return false;
        }
        return true;
    }

    /*
     *  Return Bool true if it is an simple YEARLY recur with BYMONTH/BYMONTHDAY but without BYDAYs
     *
     * @param array $recur
     * @return bool
     * @static
     * @since  2.27.16 - 2019-03-06
     */
    public static function isSimpleYearlyRecur1( array $recur ) {
        if( Vcalendar::YEARLY != $recur[Vcalendar::FREQ ] ) {
            return false;
        }
        if( isset( $recur[Vcalendar::BYMONTHDAY] ) && ! isset( $recur[Vcalendar::BYMONTH] )) {
            return false;
        }
        foreach( self::$RECURBYX as $byX ) {
            if( Vcalendar::BYMONTHDAY == $byX ) {
                continue;
            }
            if( isset( $recur[$byX])) {
                return false;
            }
        }
        if( isset( $recur[Vcalendar::BYDAY] )) {
            return false;
        }
        return true;
    }

    /**
     * Return initiated base values for recur_x_Simple
     *
     * If missing endDate/UNTIL, stopDate is set to (const) EXTENDYEAR year from startdate (emergency break)
     *
     * @param array $recur
     * @param mixed $wDateIn
     * @param mixed $fcnStartIn
     * @param mixed $fcnEndIn
     * @return array
     * @throws Exception
     * @access private
     * @static
     * @since  2.29.2 - 2019-03-03
     */
    private static function getRecurSimpleBase( array $recur, $wDateIn, $fcnStartIn, $fcnEndIn ) {
        static $MOD  = ' years';
        $wDate       = self::dateToDateTime( $wDateIn );
        $wDateYmd    = $wDate->format( self::$YMD );
        $fcnStart    = self::dateToDateTime( $fcnStartIn );
        $fcnStartYmd = $fcnStart->format( self::$YMD );
        if( empty( $fcnEndIn )) {
            $base = ( $wDateYmd > $fcnStartYmd ) ? clone $wDate : clone $fcnStart;
            $base->modify( self::EXTENDYEAR . $MOD ); // max??
        }
        else {
            $base = self::dateToDateTime( $fcnEndIn );
        }
        $endYmd = $base->format( self::$YMD );
        if( isset( $recur[Vcalendar::UNTIL] )) {
            $untilYmd = $recur[Vcalendar::UNTIL]->format( self::$YMD );
            if( $untilYmd < $endYmd ) {
                $endYmd = $untilYmd;
            }
        }
        return [
            $wDate,
            $wDateYmd,
            $fcnStartYmd,
            $endYmd
        ];
    }

    /**
     * Return array $result with dates based on a simple DAILY recur pattern
     *
     * Recur INTERVAL required
     * Recur BYMONTH opt, BYMONTHDAY opt, BYDAY opt (checked in order)
     * If missing endDate/UNTIL, stopDate is set to (const) EXTENDYEAR year from startdate (emergency break)
     *
     * @param array $recur      pattern for recurrency (only value part, params ignored)
     *                          Required FREQ && (COUNT || UNTIL) && INTERVAL (1 if missing)
     * @param mixed $wDateIn    component start date, string / array / (datetime) obj
     * @param mixed $fcnStartIn start date, string / array / (datetime) obj
     * @param mixed $fcnEndIn   end date, string / array / (datetime) obj
     * @return array            array([Ymd] => bool)
     * @throws Exception
     * @static
     * @since  2.27.16 - 2019-03-03
     */
    public static function recurDailySimple( array $recur, $wDateIn, $fcnStartIn, $fcnEndIn = false ) {
        list( $wDate, $wDateYmd, $fcnStartYmd, $endYmd ) =
            self::getRecurSimpleBase( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
        if( $wDateYmd > $endYmd ) {
            return [];
        }
        $modifyX   = sprintf( self::$FMTX, $recur[Vcalendar::INTERVAL] );
        $count     = ( isset( $recur[Vcalendar::COUNT] )) ? $recur[Vcalendar::COUNT] : PHP_INT_MAX;
        $result    = $byDayList = $byMonthList = $byMonthDayList = $monthDays = [];
        $hasMonth  = $hasMonthDays = false;
        $byDayList = ( isset( $recur[Vcalendar::BYDAY] ))
            ? self::getRecurByDaysWithNoEnumeratedWeekdays( $recur[Vcalendar::BYDAY] ) : [];
        $byMonthList    = self::getRecurByMonth( $recur, $hasMonth );
        $byMonthDayList = self::getRecurByMonthDay( $recur, $hasMonthDays );
        $bck1Month = $bck2Month = null;
        $wDate     = $wDate->modify( $modifyX );
        $x         = 1;
        while( $x < $count ) {
            if( $endYmd < $wDate->format( self::$YMD )) {
                break;
            }
            $currMonth = (int) $wDate->format( 'm' );
            if( $hasMonth ) {
                if( $bck1Month != $currMonth ) {
                    while( ! in_array((int) $wDate->format( self::$LCM ), $byMonthList )) {
                        $wDate     = $wDate->modify( $modifyX );
                        $currMonth = (int) $wDate->format( self::$LCM );
                    }
                }
                $bck1Month = $currMonth;
                $bck2Month = null;
            }
            $Ymd   = $wDate->format( self::$YMD );
            if( $Ymd <= $fcnStartYmd ) {
                continue;
            }
            if( $hasMonthDays ) {
                if( $bck2Month != $currMonth ) {
                    $bck2Month = $currMonth;
                    $monthDays = self::getMonthDaysFromByMonthDayList(
                        (int) $wDate->format( self::$LCT ),
                        $byMonthDayList
                    );
                }
            }
            if( self::inList( $wDate->format( self::$LCD ), $monthDays ) &&
                self::inList( $wDate->format( self::$LCW ), $byDayList )) {
                $result[$Ymd] = true;
                $x += 1;
            }
            $wDate     = $wDate->modify( $modifyX );
        } // end while
        return $result;
    }

    /**
     * Return array $result with dates based on a simple WEEKLY recur pattern without BYDAYSs
     *
     * Recur INTERVAL required
     * Recur BYMONTH opt.
     * If missing endDate/UNTIL, stopDate is set to (const) EXTENDYEAR year from startdate (emergency break)
     *
     * @param array $recur      pattern for recurrency (only value part, params ignored)
     *                          Required FREQ && (COUNT || UNTIL) && INTERVAL (1 if missing)
     * @param mixed $wDateIn    component start date, string / array / (datetime) obj
     * @param mixed $fcnStartIn start date, string / array / (datetime) obj
     * @param mixed $fcnEndIn   end date, string / array / (datetime) obj
     * @return array            array([Ymd] => bool)
     * @throws Exception
     * @static
     * @since  2.27.16 - 2019-03-03
     */
    public static function recurWeeklySimple1( array $recur, $wDateIn, $fcnStartIn, $fcnEndIn = false ) {
        list( $wDate, $wDateYmd, $fcnStartYmd, $endYmd ) =
            self::getRecurSimpleBase( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
        if( $wDateYmd > $endYmd ) {
            return [];
        }
        $result   = [];
        $x        = 1;
        $count    = ( isset( $recur[Vcalendar::COUNT] )) ? $recur[Vcalendar::COUNT] : PHP_INT_MAX;
        $byMonthList = [];
        if( isset( $recur[Vcalendar::BYMONTH] )) {
            $byMonthList = is_array( $recur[Vcalendar::BYMONTH] )
                ? $recur[Vcalendar::BYMONTH] : [$recur[Vcalendar::BYMONTH]];
        }
        $modifyX = sprintf( self::$FMTX, ( $recur[Vcalendar::INTERVAL] * 7 ));
        while( $x < $count ) {
            $wDate = $wDate->modify( $modifyX );
            $Ymd   = $wDate->format( self::$YMD );
            if( $endYmd < $Ymd ) {
                break;
            }
            if( $Ymd <= $fcnStartYmd ) {
                continue;
            }
            if( self::inList( $wDate->format( self::$LCM ), $byMonthList )) {
                $result[$Ymd] = true;
                $x           += 1;
            }
        } // end while
        return $result;
    }

    /**
     * Return array $result with dates based on a simple WEEKLY recur pattern with BYDAYs
     *
     * Recur INTERVAL required
     * Recur BYDAY required, BYMONTH opt.
     * If missing endDate/UNTIL, stopDate is set to (const) EXTENDYEAR year from startdate (emergency break)
     *
     * @param array $recur      pattern for recurrency (only value part, params ignored)
     *                          Required FREQ && (COUNT || UNTIL) && INTERVAL (1 if missing)
     * @param mixed $wDateIn    component start date, string / array / (datetime) obj
     * @param mixed $fcnStartIn start date, string / array / (datetime) obj
     * @param mixed $fcnEndIn   end date, string / array / (datetime) obj
     * @return array            array([Ymd] => bool)
     * @throws Exception
     * @static
     * @since  2.27.16 - 2019-03-03
     */
    public static function recurWeeklySimple2( array $recur, $wDateIn, $fcnStartIn, $fcnEndIn = false ) {
        static $MINUS1DAY = '-1 day';
        list( $wDate, $wDateYmd, $fcnStartYmd, $endYmd ) =
            self::getRecurSimpleBase( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
        if( $wDateYmd > $endYmd ) {
            return [];
        }
        $result    = [];
        $x         = 1;
        $count     = ( isset( $recur[Vcalendar::COUNT] )) ? $recur[Vcalendar::COUNT] : PHP_INT_MAX;
        $byDayList = self::getRecurByDaysWithNoEnumeratedWeekdays( $recur[Vcalendar::BYDAY] );
        $sts       = false;

        $byMonthList = self::getRecurByMonth( $recur, $sts );
        $modify1     = sprintf( self::$FMTX, 1 );
        $targetWeekNo = (int) $wDate->format( self::$UCW );
        // go back to first day of week or first day in month
        while(( 1 != $wDate->format( self::$LCW )) &&
              ( 1 != $wDate->format( self::$LCJ ))) {
            $wDate = $wDate->modify( $MINUS1DAY );
        }
        while( $x < $count ) {
            $currWeekNo = (int) $wDate->format( self::$UCW );
            $Ymd        = $wDate->format( self::$YMD );
            switch( true ) {
                case( $Ymd <= $fcnStartYmd ) :
                    $wDate = $wDate->modify( $modify1 );
                    break;
                case( $endYmd < $Ymd ) :
                    break 2;
                case( $currWeekNo == $targetWeekNo ) :
                    if( self::inList( $wDate->format( self::$LCM ), $byMonthList )) {
                        if( self::inList( $wDate->format( self::$LCW ), $byDayList )) {
                            $result[$Ymd] = true;
                        }
                    }
                    $wDate = $wDate->modify( $modify1 );
                    break;
                default :
                    // now is the first day of next week
                    if( 1 < $recur[Vcalendar::INTERVAL] ) {
                        $modifyX = sprintf( self::$FMTX,  ( 7 * ( $recur[Vcalendar::INTERVAL] - 1 )));
                        $wDate   = $wDate->modify( $modifyX );
                    }
                    $targetWeekNo = (int) $wDate->format( self::$UCW );
                    break;
            } // end switch
        } // end while
        return $result;
    }

    /**
     * Return array $result with dates based on a simple MONTHLY recur pattern without BYDAYSs
     *
     * Recur INTERVAL required
     * Recur BYMONTH opt, BYMONTHDAY opt
     * If missing endDate/UNTIL, stopDate is set to (const) EXTENDYEAR year from startdate (emergency break)
     *
     * @param array $recur      pattern for recurrency (only value part, params ignored)
     *                          Required FREQ && (COUNT || UNTIL) && INTERVAL (1 if missing)
     * @param mixed $wDateIn    component start date, string / array / (datetime) obj
     * @param mixed $fcnStartIn start date, string / array / (datetime) obj
     * @param mixed $fcnEndIn   end date, string / array / (datetime) obj
     * @return array            array([Ymd] => bool)
     * @throws Exception
     * @static
     * @since  2.27.16 - 2019-03-03
     */
    public static function recurMonthlySimple1( array $recur, $wDateIn, $fcnStartIn, $fcnEndIn = false ) {
        list( $wDate, $wDateYmd, $fcnStartYmd, $endYmd ) =
            self::getRecurSimpleBase( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
        if( $wDateYmd > $endYmd ) {
            return [];
        }
        $result    = $byMonthList = $byMonthDayList = $monthDays = [];
        $count     = ( isset( $recur[Vcalendar::COUNT] )) ? $recur[Vcalendar::COUNT] : PHP_INT_MAX;
        $hasMonthDays = $sts = false;
        $byMonthList    = self::getRecurByMonth( $recur, $sts );
        $byMonthDayList = self::getRecurByMonthDay( $recur, $hasMonthDays );
        $year      = (int) $wDate->format( self::$UCY );
        $month     = (int) $wDate->format( self::$LCM );
        $currMonth = $daysInMonth = null;
        if( $hasMonthDays ) {
            $day         = 1;
            $monthDays   = self::getMonthDaysFromByMonthDayList(
                (int) $wDate->format( self::$LCT ),
                $byMonthDayList
            );
            $currMonth   = $month;
        }
        else {
            $day = (int) $wDate->format( self::$LCJ );
        }
        $x    = 1;
        while( $x < $count ) {
            if( $month != $currMonth ) {
                $month += $recur[Vcalendar::INTERVAL];
                if( 12 < $month ) {
                    $year  += (int) floor( $month / 12 );
                    $month  = (int) ( $month % 12 );
                }
                if( ! self::inList( $month, $byMonthList )) {
                    $currMonth = null;
                    continue;
                }
                $currMonth  = $month;
                if( $hasMonthDays ) {
                    $day    = 1;
                    $monthDays = self::getMonthDaysFromByMonthDayList(
                        (int) $wDate->setDate( $year, $month, $day )->format( self::$LCT ),
                        $byMonthDayList
                    );
                }
            }
            elseif( $day == $daysInMonth ) {
                $currMonth = null;
                continue;
            }
            else {
                $day += 1;
            }
            if( ! checkdate( (int) $month, (int) $day, (int) $year )) {
                $currMonth = null;
                continue;
            }
            $Ymd = sprintf( self::$YMDs, $year, $month, $day );
            if( $endYmd < $Ymd ) {
                break;
            }
            if( $Ymd <= $fcnStartYmd ) {
                continue;
            }
            if( self::inList( $day, $monthDays )) {
                $result[$Ymd]  = true;
                $x            += 1;
                if( $x >= $count ) {
                    break;
                }
                if( ! $hasMonthDays ) {
                    $currMonth = null;
                }
            }
        } // end while
        return $result;
    }

    /**
     * Return array $result with dates based on a simple YEARLY recur pattern without BYDAYSs
     *
     * Recur INTERVAL required
     * Recur BYMONTH opt, with or without BYMONTHDAY
     * If missing endDate/UNTIL, stopDate is set to (const) EXTENDYEAR year from startdate (emergency break)
     *
     * @param array $recur      pattern for recurrency (only value part, params ignored)
     *                          Required FREQ && (COUNT || UNTIL) && INTERVAL (1 if missing)
     * @param mixed $wDateIn    component start date, string / array / (datetime) obj
     * @param mixed $fcnStartIn start date, string / array / (datetime) obj
     * @param mixed $fcnEndIn   end date, string / array / (datetime) obj
     * @return array            array([Ymd] => bool)
     * @throws Exception
     * @static
     * @since  2.27.16 - 2019-03-06
     */
    public static function recurYearlySimple1( array $recur, $wDateIn, $fcnStartIn, $fcnEndIn = false ) {
        list( $wDate, $wDateYmd, $fcnStartYmd, $endYmd ) =
            self::getRecurSimpleBase( $recur, $wDateIn, $fcnStartIn, $fcnEndIn );
        if( $wDateYmd > $endYmd ) {
            return [];
        }
        $result   = $byMonthList = $byWeeknoList = $byMonthDayList = $monthDaysList = [];
        $x        = 1;
        $count    = ( isset( $recur[Vcalendar::COUNT] )) ? $recur[Vcalendar::COUNT] : PHP_INT_MAX;
        $hasMonth = $hasMonthDays = false;
        $byMonthList    = self::getRecurByMonth( $recur, $hasMonth );
        $byMonthDayList = self::getRecurByMonthDay( $recur, $hasMonthDays );
        $year      = $currYear = (int) $wDate->format( self::$UCY );
        $month     = (int) $wDate->format( self::$LCM );
        $day       = (int) $wDate->format( self::$LCJ );
        $currMonth = $month;
        if( empty( $byMonthList )) {
            $currYear  = null;
            $currMonth = null;
        }
        $isLastMonth = false;
        while(( $x < $count ) && ( $endYmd >= $wDate->format( self::$YMD ))) {
            if( $year != $currYear ) {
                $year     += $recur[Vcalendar::INTERVAL];
                $currYear  = $year;
                if( $hasMonth ) {
                    $month = 1;
                    $currMonth = null;
                }
                $wDate->setDate( $year, $month, $day );
            }  // end if currYear
            if( $hasMonth && ( $month != $currMonth )) {
                switch( true ) {
                    case( ! in_array( $month, $byMonthList )) :
                        $currMonth = $month = reset( $byMonthList );
                        break;
                    case( $isLastMonth ) :
                        $currMonth = $month = reset( $byMonthList );
                        break;
                    case( 1 == count( $byMonthList )) :
                        $currYear    = null;
                        $isLastMonth = true;
                        continue 2;
                        break;
                    default :
                        $nextKey   = array_keys( $byMonthList, $month )[0] + 1;
                        $currMonth = $month = $byMonthList[$nextKey];
                        break;
                }
                $isLastMonth = ( $month == end( $byMonthList ));
                $wDate->setDate( $year, $month, $day );
            } // end if currMonth
            $Ymd = $wDate->format( self::$YMD );
            if( $endYmd < $Ymd ) {
                break;
            }
            if( $hasMonthDays ) {
                foreach( self::getMonthDaysFromByMonthDayList(
                    (int) $wDate->format( self::$LCT ),
                    $byMonthDayList
                ) as $monthDay ) {
                    $Ymd = sprintf( self::$YMDs, $year, $month, $monthDay );
                    if( $Ymd <= $fcnStartYmd ) {
                        continue;
                    }
                    if( $endYmd < $Ymd ) {
                        break 2;
                    }
                    $result[$Ymd] = true;
                    $x           += 1;
                    if( $x >= $count ) {
                        break 2;
                    }
                } // end foreach
            }
            elseif( $Ymd > $fcnStartYmd ) {
                $result[$Ymd] = true;
                $x           += 1;
            }
            if( $hasMonth ) {
                $currMonth = null;
                if( $isLastMonth ) {
                    $currYear = null;
                }
            }
            else {
                $currYear = null;
            }
        } // end while
        return $result;
    }

    /*
     * Return array, recur BYMONTH
     *
     * @param array $recur
     * @param bool  $hasMonth
     * @return array
     * @access private
     * @static
     * @since  2.29.11 - 2019-08-30
     */
    private static function getRecurByMonth( array $recur, & $hasMonth = false ) {
        $byMonthList = [];
        if( isset( $recur[Vcalendar::BYMONTH] )) {
            $byMonthList = is_array( $recur[Vcalendar::BYMONTH] )
                ? $recur[Vcalendar::BYMONTH] : [ $recur[Vcalendar::BYMONTH] ];
            sort( $byMonthList, SORT_NUMERIC );
            $hasMonth = true;
        }
        return $byMonthList;
    }

    /*
     * Return array BYMONTHDAY
     *
     * @param array $recur
     * @param bool  $hasMonthDays
     * @return array
     * @access private
     * @static
     * @since  2.29.11 - 2019-08-30
     */
    private static function getRecurByMonthDay( array $recur, & $hasMonthDays = false ) {
        $byMonthDayList = [];
        if( isset( $recur[Vcalendar::BYMONTHDAY] )) {
            $byMonthDayList = is_array( $recur[Vcalendar::BYMONTHDAY] )
                ? $recur[Vcalendar::BYMONTHDAY] : [ $recur[Vcalendar::BYMONTHDAY] ];
            $hasMonthDays = true;
        }
        return $byMonthDayList;
    }

    /*
     * Return bool true if byXxxList is empty or needle found in byXxxList
     *
     * @param int   $needle
     * @param array $byXxxList
     * @return array
     * @access private
     * @static
     * @since  2.27.16 - 2019-03-04
     */
    private static function inList( $needle, array $byXxxList ) {
        if( empty( $byXxxList )) {
            return true;
        }
        return in_array( $needle, $byXxxList );
    }

    /*
     *  Return array list of month days from $byMonthDayList
     *
     * @param int   $daysInMonth
     * @param array $byMonthDayList
     * @return array
     * @access private
     * @static
     * @since  2.27.16 - 2019-03-06
     */
    public static function getMonthDaysFromByMonthDayList( $daysInMonth, array $byMonthDayList ) {
        $list = [];
        foreach( $byMonthDayList as $byMonthDay ) {
            $list[] =  ( 0 < $byMonthDay ) ? $byMonthDay : ( $daysInMonth + 1 + $byMonthDay );
        }
        $list = array_values( array_unique( $list ));
        sort( $list, SORT_NUMERIC );
        return $list;
    }

    /*
     * Return recur BYDAYs with no enumerated weekday(s) ( ex '-1 TH' )
     *
     * @param array $recurByDay
     * @return array
     * @static
     * @since  2.27.16 - 2019-03-03
     */
    private static function getRecurByDaysWithNoEnumeratedWeekdays( array $recurByDay ) {
        $dayArr = array_flip( self::$DAYNAMES );
        $list   = [];
        foreach( $recurByDay as $BYDAYx => $BYDAYv ) {
            if( ctype_digit((string) $BYDAYx ) && is_array( $BYDAYv )) {
                foreach( $BYDAYv as $BYDAYx2 => $BYDAYv2 ) {
                    if( Vcalendar::DAY == $BYDAYx2 ) {
                        $list[] = $dayArr[$BYDAYv2];
                    }
                }
            }
            elseif( Vcalendar::DAY == $BYDAYx ) {
                $list[] = $dayArr[$BYDAYv];
            }
        } // end foreach
        return $list;
    }

    /*
     *  Return DateTime
     *
     * @param DateTime|string $input
     * @return DateTime
     * @throws Exception
     * @throws InvalidArgumentException
     * @static
     * @since  2.27.16 - 2019-03-03
     */
    private static function dateToDateTime( $input ) {
        if( $input instanceof DateTime ) {
            return clone $input;
        }
        list( $dateStr, $timezonePart ) = DateTimeFactory::splitIntoDateStrAndTimezone( $input );
        try {
            $output = DateTimeFactory::getDateTimeWithTimezoneFromString(
                $dateStr,
                $timezonePart,
                Vcalendar::UTC
            );
        }
        catch( Exception $e ) {
            throw $e;
        }
        return $output;
    }

    /**
     * Update array $result with dates based on a recur pattern
     *
     * If missing, UNTIL is set 1 year from startdate (emergency break)
     *
     * @param array           $result      array to update, array([Y-m-d] => bool)
     * @param array           $recur       pattern for recurrency (only value part, params ignored)
     * @param string|DateTime $wDateIn     component start date
     * @param string|DateTime $fcnStartIn  start date
     * @param string|DateTime $fcnEndIn    end date
     * @throws Exception
     * @static
     * @since  2.26 - 2018-11-10
     * @todo   BYHOUR, BYMINUTE, BYSECOND, WEEKLY at year end/start OR not at all
     */
    public static function fullRecur2date(
        & $result,
        $recur,
        $wDateIn,
        $fcnStartIn,
        $fcnEndIn = null
    ) {
        static $YEAR2DAYARR = [ 'YEARLY', 'MONTHLY', 'WEEKLY', 'DAILY' ];
        if( ! isset( $recur[Vcalendar::FREQ] )) { // "MUST be specified.. ."
            $recur[Vcalendar::FREQ] = Vcalendar::DAILY;
        } // ??
        if( ! isset( $recur[Vcalendar::INTERVAL] )) {
            $recur[Vcalendar::INTERVAL] = 1;
        }
        $wDate       = self::reFormatDate( $wDateIn );
        $wDateYMD    = sprintf( self::$YMDs, $wDate[self::$LCYEAR], $wDate[self::$LCMONTH], $wDate[self::$LCDAY] );
        $wDateHis    = sprintf( self::$HIS, $wDate[self::$LCHOUR], $wDate[self::$LCMIN], $wDate[self::$LCSEC] );
        $untilHis    = $wDateHis;
        $fcnStart    = self::reFormatDate( $fcnStartIn );
        $fcnStartYMD = sprintf(
            self::$YMDs, $fcnStart[self::$LCYEAR], $fcnStart[self::$LCMONTH], $fcnStart[self::$LCDAY]
        );
        if( ! empty( $fcnEndIn )) {
            $fcnEnd = self::reFormatDate( $fcnEndIn );
        }
        else {
            $fcnEnd                = $fcnStart;
            $fcnEnd[self::$LCYEAR] += self::EXTENDYEAR;
        }
        $fcnEndYMD   = sprintf( self::$YMDs, $fcnEnd[self::$LCYEAR], $fcnEnd[self::$LCMONTH], $fcnEnd[self::$LCDAY] );
        if( ! isset( $recur[Vcalendar::COUNT] ) && ! isset( $recur[Vcalendar::UNTIL] )) {
            $recur[Vcalendar::UNTIL] = $fcnEnd; // ??
        } // create break
        if( isset( $recur[Vcalendar::UNTIL] )) {
            $recur[Vcalendar::UNTIL] = self::reFormatDate( $recur[Vcalendar::UNTIL] );
            if( $fcnEnd > $recur[Vcalendar::UNTIL] ) {
                $fcnEnd    = $recur[Vcalendar::UNTIL]; // emergency break
                $fcnEndYMD = sprintf(
                    self::$YMDs, $fcnEnd[self::$LCYEAR], $fcnEnd[self::$LCMONTH], $fcnEnd[self::$LCDAY]
                );
            }
            if( isset( $recur[Vcalendar::UNTIL][self::$LCHOUR] )) {
                $untilHis = sprintf(
                    self::$HIS,
                    $recur[Vcalendar::UNTIL][self::$LCHOUR],
                    $recur[Vcalendar::UNTIL][self::$LCMIN],
                    $recur[Vcalendar::UNTIL][self::$LCSEC]
                );
            }
            else {
                $untilHis = sprintf( self::$HIS, 23, 59, 59 );
            }
        } // end if( isset( $recur[Vcalendar::UNTIL] ))
        if( $wDateYMD > $fcnEndYMD ) {
            return; // nothing to do.. .
        }
        $recurFreqIsYearly  = ( Vcalendar::YEARLY  == $recur[Vcalendar::FREQ] );
        $recurFreqIsMonthly = ( Vcalendar::MONTHLY == $recur[Vcalendar::FREQ] );
        $recurFreqIsWeekly  = ( Vcalendar::WEEKLY  == $recur[Vcalendar::FREQ] );
        $recurFreqIsDaily   = ( Vcalendar::DAILY   == $recur[Vcalendar::FREQ] );
        $wkst = ( Util::issetKeyAndEquals( $recur, Vcalendar::WKST, Vcalendar::SU ))
            ? 24 * 60 * 60 : 0; // ??
        $recurCount = ( ! isset( $recur[Vcalendar::BYSETPOS] )) ? 1 : 0; // DTSTART counts as the first occurrence
        /* find out how to step up dates and set index for interval \count */
        $step = [];
        if( $recurFreqIsYearly ) {
            $step[self::$LCYEAR] = 1;
        }
        elseif( $recurFreqIsMonthly ) {
            $step[self::$LCMONTH] = 1;
        }
        elseif( $recurFreqIsWeekly ) {
            $step[self::$LCDAY] = 7;
        }
        else {
            $step[self::$LCDAY] = 1;
        }
        if( isset( $step[self::$LCYEAR] ) && isset( $recur[Vcalendar::BYMONTH] )) {
            $step = [ self::$LCMONTH => 1 ];
        }
        if( empty( $step ) && isset( $recur[Vcalendar::BYWEEKNO] )) { // ??
            $step = [ self::$LCDAY => 7 ];
        }
        if( isset( $recur[Vcalendar::BYYEARDAY] ) ||
            isset( $recur[Vcalendar::BYMONTHDAY] ) ||
            isset( $recur[Vcalendar::BYDAY] )) {
            $step = [ self::$LCDAY => 1 ];
        }
        $intervalArr = [];
        if( 1 < $recur[Vcalendar::INTERVAL] ) {
            $intervalIx  = self::recurIntervalIx( $recur[Vcalendar::FREQ], $wDate, $wkst );
            $intervalArr = [ $intervalIx => 0 ];
        }
        if( isset( $recur[Vcalendar::BYSETPOS] )) { // save start date + weekno
            $bysetPosymd1 = $bysetPosymd2 = $bysetPosw1 = $bysetPosw2 = [];
            if( is_array( $recur[Vcalendar::BYSETPOS] )) {
                foreach( $recur[Vcalendar::BYSETPOS] as $bix => $bval ) {
                    $recur[Vcalendar::BYSETPOS][$bix] = (int) $bval;
                }
            }
            else {
                $recur[Vcalendar::BYSETPOS] = [ (int) $recur[Vcalendar::BYSETPOS] ];
            }
            if( $recurFreqIsYearly ) {
                // start from beginning of year
                $wDate[self::$LCMONTH] = $wDate[self::$LCDAY] = 1;
                $wDateYMD              = sprintf(
                    self::$YMDs, $wDate[self::$LCYEAR], $wDate[self::$LCMONTH], $wDate[self::$LCDAY]
                );
                // make sure to count last year
                self::stepDate( $fcnEnd, $fcnEndYMD, [ self::$LCYEAR => 1 ] );
            }
            elseif( $recurFreqIsMonthly ) {
                // start from beginning of month
                $wDate[self::$LCDAY] = 1;
                $wDateYMD            = sprintf(
                    self::$YMDs, $wDate[self::$LCYEAR], $wDate[self::$LCMONTH], $wDate[self::$LCDAY]
                );
                // make sure to count last month
                self::stepDate( $fcnEnd, $fcnEndYMD, [ self::$LCMONTH => 1 ] );
            }
            else {
                self::stepDate( $fcnEnd, $fcnEndYMD, $step );
            } // make sure to \count whole last period
            $bysetPosWold = self::getWeekNumber(
                0, 0, $wkst,
                $wDate[self::$LCMONTH], $wDate[self::$LCDAY], $wDate[self::$LCYEAR]
            );
            $bysetPosYold = $wDate[self::$LCYEAR];
            $bysetPosMold = $wDate[self::$LCMONTH];
            $bysetPosDold = $wDate[self::$LCDAY];
        } // end if( isset( $recur[Vcalendar::BYSETPOS] ))
        else {
            self::stepDate( $wDate, $wDateYMD, $step );
        }
        $yearOld = null;
        $dayCnts  = [];
        /* MAIN LOOP */
        while( true ) {
            if( $wDateYMD . $wDateHis > $fcnEndYMD . $untilHis ) {
                break;
            }
            if( isset( $recur[Vcalendar::COUNT] ) &&
                ( $recurCount >= $recur[Vcalendar::COUNT] )) {
                break;
            }
            if( $yearOld != $wDate[self::$LCYEAR] ) { // $yearOld=null 1:st time
                $yearOld  = $wDate[self::$LCYEAR];
                $dayCnts  = self::initDayCnts( $wDate, $recur, $wkst );
            }
            /* check interval */
            if( 1 < $recur[Vcalendar::INTERVAL] ) {
                /* create interval index */
                $intervalIx = self::recurIntervalIx( $recur[Vcalendar::FREQ], $wDate, $wkst );
                /* check interval */
                $currentKey = array_keys( $intervalArr );
                $currentKey = end( $currentKey ); // get last index
                if( $currentKey != $intervalIx ) {
                    $intervalArr = [ $intervalIx => ( $intervalArr[$currentKey] + 1 ) ];
                }
                if(( $recur[Vcalendar::INTERVAL] != $intervalArr[$intervalIx] ) &&
                    ( 0 != $intervalArr[$intervalIx] )) {
                    /* step up date */
                    self::stepDate( $wDate, $wDateYMD, $step );
                    continue;
                }
                else { // continue within the selected interval
                    $intervalArr[$intervalIx] = 0;
                }
            } // endif( 1 < $recur['INTERVAL'] )
            $updateOK = true;
            if( $updateOK && isset( $recur[Vcalendar::BYMONTH] )) {
                $updateOK = self::recurBYcntcheck(
                    $recur[Vcalendar::BYMONTH],
                    $wDate[self::$LCMONTH],
                    ( $wDate[self::$LCMONTH] - 13 )
                );
            }
            if( $updateOK && isset( $recur[Vcalendar::BYWEEKNO] )) {
                $updateOK = self::recurBYcntcheck(
                    $recur[Vcalendar::BYWEEKNO],
                    $dayCnts[$wDate[self::$LCMONTH]][$wDate[self::$LCDAY]][self::$WEEKNO_UP],
                    $dayCnts[$wDate[self::$LCMONTH]][$wDate[self::$LCDAY]][self::$WEEKNO_DOWN]
                );
            }
            if( $updateOK && isset( $recur[Vcalendar::BYYEARDAY] )) {
                $updateOK = self::recurBYcntcheck(
                    $recur[Vcalendar::BYYEARDAY],
                    $dayCnts[$wDate[self::$LCMONTH]][$wDate[self::$LCDAY]][self::$YEARCNT_UP],
                    $dayCnts[$wDate[self::$LCMONTH]][$wDate[self::$LCDAY]][self::$YEARCNT_DOWN]
                );
            }
            if( $updateOK && isset( $recur[Vcalendar::BYMONTHDAY] )) {
                $updateOK = self::recurBYcntcheck(
                    $recur[Vcalendar::BYMONTHDAY],
                    $wDate[self::$LCDAY],
                    $dayCnts[$wDate[self::$LCMONTH]][$wDate[self::$LCDAY]][self::$MONTHCNT_DOWN]
                );
            }
            if( $updateOK && isset( $recur[Vcalendar::BYDAY] )) {
                $updateOK = false;
                $m        = $wDate[self::$LCMONTH];
                $d        = $wDate[self::$LCDAY];
                if( isset( $recur[Vcalendar::BYDAY][Vcalendar::DAY] )) { // single day, opt with year/month day order no
                    $dayNumberExists = $dayNumberSw = $dayNameSw = false;
                    if( $recur[Vcalendar::BYDAY][Vcalendar::DAY] == $dayCnts[$m][$d][Vcalendar::DAY] ) {
                        $dayNameSw = true;
                    }
                    if( isset( $recur[Vcalendar::BYDAY][0] )) {
                        $dayNumberExists = true;
                        if( $recurFreqIsMonthly || isset( $recur[Vcalendar::BYMONTH] )) {
                            $dayNumberSw = self::recurBYcntcheck(
                                $recur[Vcalendar::BYDAY][0],
                                $dayCnts[$m][$d][self::$MONTHDAYNO_UP],
                                $dayCnts[$m][$d][self::$MONTHDAYNO_DOWN]
                            );
                        }
                        elseif( $recurFreqIsYearly ) {
                            $dayNumberSw = self::recurBYcntcheck(
                                $recur[Vcalendar::BYDAY][0],
                                $dayCnts[$m][$d][self::$YEARDAYNO_UP],
                                $dayCnts[$m][$d][self::$YEARDAYNO_DOWN]
                            );
                        }
                    }
                    if(( $dayNumberExists && $dayNumberSw && $dayNameSw ) ||
                        ( ! $dayNumberExists && ! $dayNumberSw && $dayNameSw )) {
                        $updateOK = true;
                    }
                } // end if( isset( $recur[Vcalendar::BYDAY][Vcalendar::DAY] ))
                else {  // multiple days
                    foreach( $recur[Vcalendar::BYDAY] as $byDayValue ) {
                        $dayNumberExists = $dayNumberSw = $dayNameSw = false;
                        if( isset( $byDayValue[Vcalendar::DAY] ) &&
                            ( $byDayValue[Vcalendar::DAY] == $dayCnts[$m][$d][Vcalendar::DAY] )) {
                            $dayNameSw = true;
                        }
                        if( isset( $byDayValue[0] )) {
                            $dayNumberExists = true;
                            if( $recurFreqIsMonthly || isset( $recur[Vcalendar::BYMONTH] )) {
                                $dayNumberSw = self::recurBYcntcheck(
                                    $byDayValue[Util::$ZERO],
                                    $dayCnts[$m][$d][self::$MONTHDAYNO_UP],
                                    $dayCnts[$m][$d][self::$MONTHDAYNO_DOWN]
                                );
                            }
                            elseif( $recurFreqIsYearly ) {
                                $dayNumberSw = self::recurBYcntcheck(
                                    $byDayValue[Util::$ZERO],
                                    $dayCnts[$m][$d][self::$YEARDAYNO_UP],
                                    $dayCnts[$m][$d][self::$YEARDAYNO_DOWN]
                                );
                            }
                        } // end if( isset( $byDayValue[0] ))
                        if(( $dayNumberExists && $dayNumberSw && $dayNameSw ) ||
                            ( ! $dayNumberExists && ! $dayNumberSw && $dayNameSw )) {
                            $updateOK = true;
                            break;
                        }
                    } // end foreach( $recur[Vcalendar::BYDAY] as $byDayValue )
                } // end else
            } // end if( $updateOK && isset( $recur[Vcalendar::BYDAY] ))
            /* check BYSETPOS */
            if( $updateOK ) {
                if(      isset( $recur[Vcalendar::BYSETPOS] ) &&
                    ( in_array( $recur[Vcalendar::FREQ], $YEAR2DAYARR ))) {
                    if( $recurFreqIsWeekly ) {
                        if( $bysetPosWold ==
                            $dayCnts[$wDate[self::$LCMONTH]][$wDate[self::$LCDAY]][self::$WEEKNO_UP] ) {
                            $bysetPosw1[] = $wDateYMD;
                        }
                        else {
                            $bysetPosw2[] = $wDateYMD;
                        }
                    }
                    else {
                        if(( $recurFreqIsYearly &&
                                ( $bysetPosYold == $wDate[self::$LCYEAR] )) ||
                            ( $recurFreqIsMonthly &&
                                (( $bysetPosYold == $wDate[self::$LCYEAR] ) &&
                                 ( $bysetPosMold == $wDate[self::$LCMONTH] ))) ||
                           ( $recurFreqIsDaily &&
                                (( $bysetPosYold == $wDate[self::$LCYEAR] ) &&
                                 ( $bysetPosMold == $wDate[self::$LCMONTH] ) &&
                                 ( $bysetPosDold == $wDate[self::$LCDAY] )))) {
                            $bysetPosymd1[] = $wDateYMD;
                        }
                        else {
                            $bysetPosymd2[] = $wDateYMD;
                        }
                    } // end else
                }
                else { // ! isset( $recur[Vcalendar::BYSETPOS] )
                    if( checkdate(
                        (int) $wDate[self::$LCMONTH],
                        (int) $wDate[self::$LCDAY],
                        (int) $wDate[self::$LCYEAR] )) {
                        /* update result array if BYSETPOS is not set */
                        $recurCount++;
                        if( $fcnStartYMD <= $wDateYMD ) { // only output within period
                            $result[$wDateYMD] = true;
                        }
                    }
                    $updateOK = false;
                }
            }
            /* step up date */
            self::stepDate( $wDate, $wDateYMD, $step );
            /* check if BYSETPOS is set for updating result array */
            if( $updateOK && isset( $recur[Vcalendar::BYSETPOS] )) {
                $bysetPos = false;
                if( $recurFreqIsYearly &&
                    ( $bysetPosYold != $wDate[self::$LCYEAR] )) {
                    $bysetPos     = true;
                    $bysetPosYold = $wDate[self::$LCYEAR];
                }
                elseif( $recurFreqIsMonthly &&
                        (( $bysetPosYold != $wDate[self::$LCYEAR] ) ||
                         ( $bysetPosMold != $wDate[self::$LCMONTH] ))) {
                    $bysetPos     = true;
                    $bysetPosYold = $wDate[self::$LCYEAR];
                    $bysetPosMold = $wDate[self::$LCMONTH];
                }
                elseif( $recurFreqIsWeekly ) {
                    $weekNo = self::getWeekNumber(
                        0, 0, $wkst,
                        $wDate[self::$LCMONTH], $wDate[self::$LCDAY], $wDate[self::$LCYEAR] );
                    if( $bysetPosWold != $weekNo ) {
                        $bysetPosWold = $weekNo;
                        $bysetPos     = true;
                    }
                }
                elseif( $recurFreqIsDaily &&
                    (( $bysetPosYold != $wDate[self::$LCYEAR] ) ||
                     ( $bysetPosMold != $wDate[self::$LCMONTH] ) ||
                     ( $bysetPosDold != $wDate[self::$LCDAY] ))) {
                    $bysetPos     = true;
                    $bysetPosYold = $wDate[self::$LCYEAR];
                    $bysetPosMold = $wDate[self::$LCMONTH];
                    $bysetPosDold = $wDate[self::$LCDAY];
                }
                if( $bysetPos ) {
                    if( isset( $recur[Vcalendar::BYWEEKNO] )) {
                        $bysetPosArr1 = &$bysetPosw1;
                        $bysetPosArr2 = &$bysetPosw2;
                    }
                    else {
                        $bysetPosArr1 = &$bysetPosymd1;
                        $bysetPosArr2 = &$bysetPosymd2;
                    }

                    foreach( $recur[Vcalendar::BYSETPOS] as $ix ) {
                        if( 0 > $ix ) { // both positive and negative BYSETPOS allowed
                            $ix = ( count( $bysetPosArr1 ) + $ix + 1 );
                        }
                        $ix--;
                        if( isset( $bysetPosArr1[$ix] )) {
                            if( $fcnStartYMD <= $bysetPosArr1[$ix] ) { // only output within period
                                $result[$bysetPosArr1[$ix]] = true;
                            }
                            $recurCount++;
                        }
                        if( isset( $recur[Vcalendar::COUNT] ) && ( $recurCount >= $recur[Vcalendar::COUNT] )) {
                            break;
                        }
                    }
                    $bysetPosArr1 = $bysetPosArr2;
                    $bysetPosArr2 = [];
                } // end if( $bysetPos )
            } // end if( $updateOK && isset( $recur['BYSETPOS'] ))
        } // end while( true )
    }

    /**
     * Checking BYDAY (etc) hits, recur2date help function
     *
     * @since  2.6.12 - 2011-01-03
     * @param array $BYvalue
     * @param int   $upValue
     * @param int   $downValue
     * @return bool
     * @access private
     * @static
     */
    private static function recurBYcntcheck( $BYvalue, $upValue, $downValue ) {
        if( is_array( $BYvalue ) &&
            ( in_array( $upValue, $BYvalue ) || in_array( $downValue, $BYvalue ))
        ) {
            return true;
        }
        elseif(( $BYvalue == $upValue ) || ( $BYvalue == $downValue )) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * (re-)Calculate internal index, recur2date help function
     *
     * @param string $freq
     * @param array  $date
     * @param int    $wkst
     * @return bool
     * @access private
     * @static
     * @since  2.26 - 2018-11-10
     */
    private static function recurIntervalIx( $freq, $date, $wkst ) {
        /* create interval index */
        switch( $freq ) {
            case Vcalendar::YEARLY :
                $intervalIx = $date[self::$LCYEAR];
                break;
            case Vcalendar::MONTHLY :
                $intervalIx = $date[self::$LCYEAR] . Util::$MINUS . $date[self::$LCMONTH];
                break;
            case Vcalendar::WEEKLY :
                $intervalIx = self::getWeekNumber(
                    0, 0, $wkst,
                    $date[self::$LCMONTH], $date[self::$LCDAY], $date[self::$LCYEAR]
                );
                break;
            case Vcalendar::DAILY :
            default:
                $intervalIx =
                    $date[self::$LCYEAR] . Util::$MINUS . $date[self::$LCMONTH] . Util::$MINUS . $date[self::$LCDAY];
                break;
        }
        return $intervalIx;
    }

    /**
     * Return updated date, array and timpstamp
     *
     * @param array  $date    date to step
     * @param string $dateYMD date YMD
     * @param array  $step    default array( Util::$LCDAY => 1 )
     * @return void
     * @access private
     * @static
     */
    private static function stepDate( & $date, & $dateYMD, $step = null ) {
        if( is_null( $step )) {
            $step = [ self::$LCDAY => 1 ];
        }
        if( ! isset( $date[self::$LCHOUR] )) {
            $date[self::$LCHOUR] = 0;
        }
        if( ! isset( $date[self::$LCMIN] )) {
            $date[self::$LCMIN] = 0;
        }
        if( ! isset( $date[self::$LCSEC] )) {
            $date[self::$LCSEC] = 0;
        }
        if( isset( $step[self::$LCDAY] )) {
            $daysInMonth = self::getDaysInMonth(
                $date[self::$LCHOUR],
                $date[self::$LCMIN],
                $date[self::$LCSEC],
                $date[self::$LCMONTH],
                $date[self::$LCDAY],
                $date[self::$LCYEAR]
            );
        }
        foreach( $step as $stepix => $stepvalue ) {
            $date[$stepix] += $stepvalue;
        }
        if( isset( $step[self::$LCMONTH] )) {
            if( 12 < $date[self::$LCMONTH] ) {
                $date[self::$LCYEAR]  += 1;
                $date[self::$LCMONTH] -= 12;
            }
        }
        elseif( isset( $step[self::$LCDAY] )) {
            if( $daysInMonth < $date[self::$LCDAY] ) {
                $date[self::$LCDAY]   -= $daysInMonth;
                $date[self::$LCMONTH] += 1;
                if( 12 < $date[self::$LCMONTH] ) {
                    $date[self::$LCYEAR]  += 1;
                    $date[self::$LCMONTH] -= 12;
                }
            }
        }
        $dateYMD = sprintf( self::$YMDs, $date[self::$LCYEAR], $date[self::$LCMONTH], $date[self::$LCDAY] );
    }

    /**
     * Return initiated $dayCnts
     *
     * @param array $wDate
     * @param array $recur
     * @param int   $wkst
     * @return array
     * @access private
     * @static
     */
    private static function initDayCnts( array $wDate, array $recur, $wkst ) {
        $dayCnts    = [];
        $yearDayCnt = [];
        $yearDays   = 0;
        foreach( self::$DAYNAMES as $dn ) {
            $yearDayCnt[$dn] = 0;
        }
        for( $m = 1; $m <= 12; $m++ ) { // count up and update up-counters
            $dayCnts[$m] = [];
            $weekDayCnt  = [];
            foreach( self::$DAYNAMES as $dn ) {
                $weekDayCnt[$dn] = 0;
            }
            $daysInMonth = self::getDaysInMonth( 0, 0, 0, $m, 1, $wDate[self::$LCYEAR] );
            for( $d = 1; $d <= $daysInMonth; $d++ ) {
                $dayCnts[$m][$d] = [];
                if( isset( $recur[Vcalendar::BYYEARDAY] )) {
                    $yearDays++;
                    $dayCnts[$m][$d][self::$YEARCNT_UP] = $yearDays;
                }
                if( isset( $recur[Vcalendar::BYDAY] )) {
                    $day = self::getDayInWeek( 0, 0, 0, $m, $d, $wDate[self::$LCYEAR] );
                    $dayCnts[$m][$d][Vcalendar::DAY] = $day;
                    $weekDayCnt[$day]++;
                    $dayCnts[$m][$d][self::$MONTHDAYNO_UP] = $weekDayCnt[$day];
                    $yearDayCnt[$day]++;
                    $dayCnts[$m][$d][self::$YEARDAYNO_UP] = $yearDayCnt[$day];
                }
                if( isset( $recur[Vcalendar::BYWEEKNO] ) || ( $recur[Vcalendar::FREQ] == Vcalendar::WEEKLY )) {
                    $dayCnts[$m][$d][self::$WEEKNO_UP] =
                        self::getWeekNumber(0,0, $wkst, $m, $d, $wDate[self::$LCYEAR] );
                }
            } // end for( $d   = 1; $d <= $daysInMonth; $d++ )
        } // end for( $m = 1; $m <= 12; $m++ )
        $daycnt     = 0;
        $yearDayCnt = [];
        if( isset( $recur[Vcalendar::BYWEEKNO] ) ||
            ( $recur[Vcalendar::FREQ] == Vcalendar::WEEKLY )) {
            $weekNo = null;
            for( $d = 31; $d > 25; $d-- ) { // get last weekno for year
                if( ! $weekNo ) {
                    $weekNo = $dayCnts[12][$d][self::$WEEKNO_UP];
                }
                elseif( $weekNo < $dayCnts[12][$d][self::$WEEKNO_UP] ) {
                    $weekNo = $dayCnts[12][$d][self::$WEEKNO_UP];
                    break;
                }
            }
        }
        for( $m = 12; $m > 0; $m-- ) { // count down and update down-counters
            $weekDayCnt = [];
            foreach( self::$DAYNAMES as $dn ) {
                $yearDayCnt[$dn] = $weekDayCnt[$dn] = 0;
            }
            $monthCnt    = 0;
            $daysInMonth = self::getDaysInMonth( 0, 0, 0, $m, 1, $wDate[self::$LCYEAR] );
            for( $d = $daysInMonth; $d > 0; $d-- ) {
                if( isset( $recur[Vcalendar::BYYEARDAY] )) {
                    $daycnt                              -= 1;
                    $dayCnts[$m][$d][self::$YEARCNT_DOWN] = $daycnt;
                }
                if( isset( $recur[Vcalendar::BYMONTHDAY] )) {
                    $monthCnt                             -= 1;
                    $dayCnts[$m][$d][self::$MONTHCNT_DOWN] = $monthCnt;
                }
                if( isset( $recur[Vcalendar::BYDAY] )) {
                    $day                                     = $dayCnts[$m][$d][Vcalendar::DAY];
                    $weekDayCnt[$day]                       -= 1;
                    $dayCnts[$m][$d][self::$MONTHDAYNO_DOWN] = $weekDayCnt[$day];
                    $yearDayCnt[$day]                       -= 1;
                    $dayCnts[$m][$d][self::$YEARDAYNO_DOWN]  = $yearDayCnt[$day];
                }
                if( isset( $recur[Vcalendar::BYWEEKNO] ) || ( $recur[Vcalendar::FREQ] == Vcalendar::WEEKLY )) {
                    $dayCnts[$m][$d][self::$WEEKNO_DOWN] = ( $dayCnts[$m][$d][self::$WEEKNO_UP] - $weekNo - 1 );
                }
            } // end for( $d = $daysInMonth; $d > 0; $d-- )
        } // end for( $m = 12; $m > 0; $m-- )
        return $dayCnts;
    }

    /**
     * Return a reformatted input date
     *
     * @param mixed $inputDate
     * @return array
     * @access private
     * @throws Exception
     * @static
     * @since  2.29.2 - 2019-07-02
     */
    private static function reFormatDate( $inputDate ) {
        static $Y = 'Y';
        static $M = 'm';
        static $D = 'd';
        static $H = 'H';
        static $I = 'i';
        static $S = 'i';
        if( ! $inputDate instanceof DateTime ) {
            $inputDate = DateTimeFactory::factory( $inputDate );
        }
        return [
            self::$LCYEAR  => (int) $inputDate->format( $Y ),
            self::$LCMONTH => (int) $inputDate->format( $M ),
            self::$LCDAY   => (int) $inputDate->format( $D ),
            self::$LCHOUR  => (int) $inputDate->format( $H ),
            self::$LCMIN   => (int) $inputDate->format( $I ),
            self::$LCSEC   => (int) $inputDate->format( $S ),
        ];
    }

    /**
     * Return week number
     *
     * @param int $hour
     * @param int $min
     * @param int $sec
     * @param int $month
     * @param int $day
     * @param int $year
     * @return int
     * @access private
     * @static
     */
    private static function getWeekNumber( $hour, $min, $sec, $month, $day, $year ) {
        return (int) date( self::$UCW, mktime( $hour, $min, $sec, $month, $day, $year ));
    }

    /**
     * Return number of days in month
     *
     * @param int $hour
     * @param int $min
     * @param int $sec
     * @param int $month
     * @param int $day
     * @param int $year
     * @return int
     * @access private
     * @static
     */
    private static function getDaysInMonth( $hour, $min, $sec, $month, $day, $year ) {
        return (int) date( self::$LCT, mktime( $hour, $min, $sec, $month, $day, $year ));
    }

    /**
     * Return day in week
     *
     * @param int $hour
     * @param int $min
     * @param int $sec
     * @param int $month
     * @param int $day
     * @param int $year
     * @return string
     * @access private
     * @static
     */
    private static function getDayInWeek( $hour, $min, $sec, $month, $day, $year ) {
        $dayNo = (int) date( self::$LCW, mktime( $hour, $min, $sec, $month, $day, $year ));
        return self::$DAYNAMES[$dayNo];
    }

}
