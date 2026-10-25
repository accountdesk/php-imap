<?php
/*
* File: EventTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for the event classes and the HasEvents trait.
*/

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Events\Event;
use Webklex\PHPIMAP\Events\FlagDeletedEvent;
use Webklex\PHPIMAP\Events\FlagNewEvent;
use Webklex\PHPIMAP\Events\FolderDeletedEvent;
use Webklex\PHPIMAP\Events\FolderMovedEvent;
use Webklex\PHPIMAP\Events\FolderNewEvent;
use Webklex\PHPIMAP\Events\MessageCopiedEvent;
use Webklex\PHPIMAP\Events\MessageDeletedEvent;
use Webklex\PHPIMAP\Events\MessageMovedEvent;
use Webklex\PHPIMAP\Events\MessageNewEvent;
use Webklex\PHPIMAP\Events\MessageRestoredEvent;
use Webklex\PHPIMAP\Exceptions\EventNotFoundException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Traits\HasEvents;

class EventTest extends TestCase {

    /**
     * @return Message
     */
    protected function message(string $subject = "Event"): Message {
        return Message::fromString("From: a@b.c\r\nSubject: $subject\r\nDate: Mon, 01 Jan 2024 10:00:00 +0000\r\n\r\nBody");
    }

    /**
     * @return Folder
     */
    protected function folder(string $path): Folder {
        return new Folder(new Client(Config::make()), $path, "/", []);
    }

    /**
     * @return object
     */
    protected function holder(): object {
        return new class {
            use HasEvents;
        };
    }

    public function testMessageEvents(): void {
        $message = $this->message();

        foreach ([MessageNewEvent::class, MessageDeletedEvent::class, MessageRestoredEvent::class] as $class) {
            $event = $class::dispatch($message);
            self::assertInstanceOf($class, $event);
            self::assertInstanceOf(Event::class, $event);
            self::assertSame($message, $event->message);
        }

        $new = $this->message("New");
        foreach ([MessageMovedEvent::class, MessageCopiedEvent::class] as $class) {
            $event = $class::dispatch($message, $new);
            self::assertInstanceOf($class, $event);
            self::assertSame($message, $event->old_message);
            self::assertSame($new, $event->new_message);
        }
    }

    public function testFlagEvents(): void {
        $message = $this->message();

        foreach ([FlagNewEvent::class, FlagDeletedEvent::class] as $class) {
            $event = $class::dispatch($message, "\\Seen");
            self::assertInstanceOf($class, $event);
            self::assertSame($message, $event->message);
            self::assertSame("\\Seen", $event->flag);
        }
    }

    public function testFolderEvents(): void {
        $old = $this->folder("INBOX/Old");
        $new = $this->folder("INBOX/New");

        foreach ([FolderNewEvent::class, FolderDeletedEvent::class] as $class) {
            $event = $class::dispatch($old);
            self::assertInstanceOf($class, $event);
            self::assertSame($old, $event->folder);
        }

        $event = FolderMovedEvent::dispatch($old, $new);
        self::assertSame($old, $event->old_folder);
        self::assertSame($new, $event->new_folder);
    }

    public function testHasEventsAccessors(): void {
        $holder = $this->holder();
        self::assertSame([], $holder->getEvents());

        $holder->setEvents(["message" => ["new" => MessageNewEvent::class]]);
        self::assertSame(MessageNewEvent::class, $holder->getEvent("message", "new"));

        // events can only be added to existing sections
        $holder->setEvent("message", "deleted", MessageDeletedEvent::class);
        $holder->setEvent("folder", "new", FolderNewEvent::class);
        self::assertSame(["message" => ["new" => MessageNewEvent::class, "deleted" => MessageDeletedEvent::class]], $holder->getEvents());
    }

    public function testUnknownSectionThrows(): void {
        $this->expectException(EventNotFoundException::class);
        $this->holder()->getEvent("folder", "new");
    }

    public function testUnknownEventInKnownSectionThrows(): void {
        $holder = $this->holder();
        $holder->setEvents(["message" => []]);

        $this->expectException(EventNotFoundException::class);
        $holder->getEvent("message", "unknown");
    }

    public function testDispatch(): void {
        $holder = $this->holder();
        $holder->setEvents(["message" => ["new" => RecordingEvent::class]]);
        RecordingEvent::$dispatched = [];

        $message = $this->message();
        $holder->dispatch("message", "new", $message, "extra");

        self::assertSame([[RecordingEvent::class, [$message, "extra"]]], RecordingEvent::$dispatched);
    }

    public function testClientProvidesDefaultEvents(): void {
        $client = new Client(Config::make());

        self::assertSame(MessageNewEvent::class, $client->getDefaultEvents("message")["new"]);
        self::assertSame(FolderMovedEvent::class, $client->getDefaultEvents("folder")["moved"]);
        self::assertSame(FlagDeletedEvent::class, $client->getDefaultEvents("flag")["deleted"]);
        self::assertSame([], $client->getDefaultEvents("unknown"));
    }
}
