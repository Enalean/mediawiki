## Basic setup

```
git clone -b REL1_35 --depth 1 https://github.com/Enalean/mediawiki
cd mediawiki/
git submodule init
git submodule update --recursive
composer update --no-dev
```

## `LocalSettings.Tuleap.php`