<?php

declare(strict_types=1);

use Resm\App;
use Resm\Auth\Role;
use Resm\Menu;

/**
 * The Main Menu, spec 6.2.
 *
 * What is pinned here is not how the tiles look but the pairing: one table
 * decides which tiles render and which routes refuse, so a tile can never
 * appear that its handler would reject, and — the direction that matters — a
 * route can never be open to a role the tile was hidden from.
 */
test('the menu order is the one the spec fixes', function (): void {
    assertSame(
        ['admin', 'officer', 'check-in', 'my-shift', 'my-shifts', 'information', 'tools'],
        array_keys(Menu::SECTIONS)
    );
});

test('a committeeman sees five tiles and neither menu', function (): void {
    $app = App::boot(dirname(__DIR__));
    $tiles = Menu::tilesFor($app, identity(Role::Committeeman, []));

    assertSame(
        ['Check In / Out', 'My Shift Status', 'My Shifts', 'Rodeo Information', 'Tools'],
        array_column($tiles, 'label')
    );
});

test('an officer gains the Officer Menu, an admin gains both', function (): void {
    $app = App::boot(dirname(__DIR__));

    $officer = array_column(Menu::tilesFor($app, identity(Role::Officer, [7])), 'label');
    assertTrue(in_array('Officer Menu', $officer, true));
    assertTrue(!in_array('Admin Menu', $officer, true), 'an officer is not an admin');

    $admin = array_column(Menu::tilesFor($app, identity(Role::Admin, [])), 'label');
    assertTrue(in_array('Officer Menu', $admin, true), 'admins have every officer function');
    assertTrue(in_array('Admin Menu', $admin, true));
});

test('tile urls are built from the mount point', function (): void {
    $app = App::boot(dirname(__DIR__));

    foreach (Menu::tilesFor($app, identity(Role::Admin, [])) as $tile) {
        assertTrue(str_starts_with($tile['url'], '/resm/'), "hard-coded path: {$tile['url']}");
    }
});

test('visibility matches for every section and role', function (): void {
    $expected = [
        // section      => [committeeman, officer, admin]
        'admin'       => [false, false, true],
        'officer'     => [false, true,  true],
        'check-in'    => [true,  true,  true],
        'my-shift'    => [true,  true,  true],
        'my-shifts'   => [true,  true,  true],
        'information' => [true,  true,  true],
        'tools'       => [true,  true,  true],
    ];

    foreach ($expected as $key => [$asCommitteeman, $asOfficer, $asAdmin]) {
        assertSame($asCommitteeman, Menu::visibleTo(identity(Role::Committeeman, []), $key), "committeeman / {$key}");
        assertSame($asOfficer, Menu::visibleTo(identity(Role::Officer, [7]), $key), "officer / {$key}");
        assertSame($asAdmin, Menu::visibleTo(identity(Role::Admin, []), $key), "admin / {$key}");
    }
});

test('a deactivated account sees no tiles at all', function (): void {
    $app = App::boot(dirname(__DIR__));

    assertCount(0, Menu::tilesFor($app, identity(Role::Admin, [], active: false)));
});

test('an unknown section is never visible', function (): void {
    assertSame(false, Menu::visibleTo(identity(Role::Admin, []), 'wp-admin'));
    assertSame(null, Menu::section('wp-admin'));
});
