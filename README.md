# Data migration plugin

Migrates user articles using a neutral format (JSON in a ZIP archive). Can be used
to transfer articles between different database types (i.e. MySQL to PostgreSQL).

## Installation

1. Git clone to ``/plugins.local/data_migration``
2. Enable in ``PLUGINS`` directive of ``config.php``
3. Plugin is invoked using command line: ``php ./update.php --help``
