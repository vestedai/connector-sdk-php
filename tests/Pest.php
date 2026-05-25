<?php

declare(strict_types=1);

uses()->in('Unit', 'Integration');

// All unit tests are pure PHP — no DB, no network, no filesystem outside
// of tests/Fixtures and sys_get_temp_dir.
