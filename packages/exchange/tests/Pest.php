<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\EditorPro\Exchange\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in(__DIR__.'/Arch', __DIR__.'/Feature/DocxZipSecurityGuardTest.php');
uses(TestCase::class, DatabaseMigrations::class)->in(__DIR__.'/Feature/DocxExchangeLifecycleTest.php');
