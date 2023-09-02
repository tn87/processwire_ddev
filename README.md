# processwire_ddev

## ddev

### config

ddev config --project-name "pw" --project-type php --docroot httpdocs --webserver-type apache-fpm

### start

ddev import-db --src=./db.sql.gz
ddev start

### helper

#### export db

ddev export-db --file=./db.sql.gz

#### import db

ddev import-db --src=./db.sql.gz

#### install sequel-ace

brew install --cask sequel-ace

## processwire

### settings

#### admin-url

/adm/

### users

tino
tino1

### added modules

TracyDebugger
