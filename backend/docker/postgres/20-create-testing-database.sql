-- Runs once, on first initialisation of the postgres volume (after the
-- postgis image's own 10_postgis.sh). The test suite migrates into this
-- database; the migrations themselves create the postgis/pg_trgm extensions.
CREATE DATABASE rebuild_testing OWNER rebuild;
