<?php
/*
* File: RecordingEvent.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Test helper event which records every dispatch.
*/

namespace Tests;

use Webklex\PHPIMAP\Events\Event;

/**
 * Class RecordingEvent
 *
 * @package Tests
 */
class RecordingEvent extends Event {

    /** @var array $dispatched */
    public static array $dispatched = [];

    /**
     * @param array $arguments
     */
    public function __construct(array $arguments) {
        self::$dispatched[] = [static::class, $arguments];
    }
}
