<?php
/*
* File: CollectionTest.php
* Category: -
* Author: -
* Created: 24.10.26 12:00
* Updated: -
*
* Description:
*  Offline tests for the paginated collections.
*/

namespace Tests;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Support\AttachmentCollection;
use Webklex\PHPIMAP\Support\FlagCollection;
use Webklex\PHPIMAP\Support\FolderCollection;
use Webklex\PHPIMAP\Support\MessageCollection;
use Webklex\PHPIMAP\Support\PaginatedCollection;

class CollectionTest extends TestCase {

    public function testAllCollectionsArePaginated(): void {
        foreach ([AttachmentCollection::class, FlagCollection::class, FolderCollection::class, MessageCollection::class] as $class) {
            self::assertInstanceOf(PaginatedCollection::class, new $class());
        }
    }

    public function testPaginateSlicesItems(): void {
        $collection = PaginatedCollection::make(range(1, 7));
        $paginator = $collection->paginate(3, 2);

        self::assertInstanceOf(LengthAwarePaginator::class, $paginator);
        self::assertSame(7, $paginator->total());
        self::assertSame(2, $paginator->currentPage());
        self::assertSame(3, $paginator->lastPage());
        self::assertSame([4, 5, 6], array_values($paginator->items()));
        self::assertSame("page", $paginator->getPageName());
    }

    public function testPaginateLastAndOutOfRangePage(): void {
        $collection = PaginatedCollection::make(range(1, 7));

        self::assertSame([7], array_values($collection->paginate(3, 3)->items()));
        self::assertSame([], array_values($collection->paginate(3, 4)->items()));
    }

    public function testPrepaginatedCollectionUsesTotal(): void {
        // the collection only holds the current page, the total is known from the server
        $collection = MessageCollection::make(["a", "b"]);
        self::assertSame(0, $collection->total());
        self::assertSame(42, $collection->total(42));
        self::assertSame(42, $collection->total());

        $paginator = $collection->paginate(2, 5, "imap_page", true);
        self::assertSame(42, $paginator->total());
        self::assertSame(21, $paginator->lastPage());
        self::assertSame(["a", "b"], $paginator->items());
        self::assertSame("imap_page", $paginator->getPageName());
    }

    public function testTotalLargerThanItemsWithoutPrepagination(): void {
        $collection = PaginatedCollection::make(range(1, 5));
        $collection->total(10);

        $paginator = $collection->paginate(2, 2);
        self::assertSame(10, $paginator->total());
        self::assertSame([3, 4], array_values($paginator->items()));
    }

    public function testPageIsResolvedFromRequest(): void {
        Paginator::currentPageResolver(function($name) {
            return $name === "custom" ? 2 : 1;
        });
        try {
            $paginator = PaginatedCollection::make(range(1, 5))->paginate(2, null, "custom");
            self::assertSame(2, $paginator->currentPage());
            self::assertSame([3, 4], array_values($paginator->items()));
        } finally {
            Paginator::currentPageResolver(function() {
                return 1;
            });
        }
    }

    public function testEmptyCollection(): void {
        $paginator = PaginatedCollection::make()->paginate(5, 1);

        self::assertSame(0, $paginator->total());
        self::assertSame([], $paginator->items());
    }
}
