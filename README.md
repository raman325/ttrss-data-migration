This is a modified version of [fox](https://git.tt-rss.org/fox)'s [ttrss-data-migration](https://git.tt-rss.org/fox/ttrss-data-migration) plugin. The version fox wrote requires the PHP server being used to have [ZIP](https://www.php.net/manual/en/book.zip.php) support. Mine did not, and I didn't have an easy way to install the extension, so I needed a way to export and import articles without using it. This version will create a folder of the JSON files generated during export and will subsequently import a folder of said JSON files. It should not require any additional PHP dependencies but YMMV.

# Data migration plugin

Migrates user articles using a neutral format (JSON files in a folder). Can be used to transfer articles between different database types (i.e. MySQL to PostgreSQL).

## Installation

1. Git clone to ``/plugins.local/data_migration_alt``
2. Enable in ``PLUGINS`` directive of ``config.php``
3. Plugin is invoked using command line: ``php ./update.php --help``

See the [wiki for the original plugin](https://git.tt-rss.org/fox/ttrss-data-migration/wiki) for more information. Note that the main difference is that in the example commands provided, instead of specifying a filename for export/import, this plugin expects a path to dump/ingest the files to/from.