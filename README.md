# e621 Post Downloader

This script will download images from e621.net using:
- Post URL / ID / MD5
- Link to image
- Set of tags

## Requirements

- **PHP** - https://www.php.net/downloads.php
- **Composer** - https://getcomposer.org

## Installation

- Clone this repository
- Run `composer install`
- Run `composer global require clue/phar-composer`
- Run `composer build`
- Copy `config.example.cfg` into `config.cfg` and fill out the login details inside
- Run the script with `php e621dlpost.phar` command
