<?php
// Script de compatibilité SQL MySQL -> PostgreSQL
require_once __DIR__ . '/../config/database.php';

global $pdo;

echo "Installation des fonctions de compatibilité MySQL dans PostgreSQL...\n";

$sql = "
-- 1. CURDATE
CREATE OR REPLACE FUNCTION CURDATE()
RETURNS date LANGUAGE sql STABLE AS \$\$ SELECT CURRENT_DATE; \$\$;

-- 2. NOW (retourne timestamp sans time zone pour match direct)
CREATE OR REPLACE FUNCTION NOW_TS()
RETURNS timestamp without time zone LANGUAGE sql STABLE AS \$\$ SELECT LOCALTIMESTAMP; \$\$;

-- 3. MONTH, YEAR, DAY
CREATE OR REPLACE FUNCTION MONTH(ts timestamp without time zone)
RETURNS integer LANGUAGE sql IMMUTABLE AS \$\$ SELECT EXTRACT(MONTH FROM ts)::integer; \$\$;

CREATE OR REPLACE FUNCTION MONTH(ts timestamptz)
RETURNS integer LANGUAGE sql IMMUTABLE AS \$\$ SELECT EXTRACT(MONTH FROM ts)::integer; \$\$;

CREATE OR REPLACE FUNCTION MONTH(dt date)
RETURNS integer LANGUAGE sql IMMUTABLE AS \$\$ SELECT EXTRACT(MONTH FROM dt)::integer; \$\$;

CREATE OR REPLACE FUNCTION YEAR(ts timestamp without time zone)
RETURNS integer LANGUAGE sql IMMUTABLE AS \$\$ SELECT EXTRACT(YEAR FROM ts)::integer; \$\$;

CREATE OR REPLACE FUNCTION YEAR(ts timestamptz)
RETURNS integer LANGUAGE sql IMMUTABLE AS \$\$ SELECT EXTRACT(YEAR FROM ts)::integer; \$\$;

CREATE OR REPLACE FUNCTION YEAR(dt date)
RETURNS integer LANGUAGE sql IMMUTABLE AS \$\$ SELECT EXTRACT(YEAR FROM dt)::integer; \$\$;

CREATE OR REPLACE FUNCTION DAY(ts timestamp without time zone)
RETURNS integer LANGUAGE sql IMMUTABLE AS \$\$ SELECT EXTRACT(DAY FROM ts)::integer; \$\$;

CREATE OR REPLACE FUNCTION DAY(ts timestamptz)
RETURNS integer LANGUAGE sql IMMUTABLE AS \$\$ SELECT EXTRACT(DAY FROM ts)::integer; \$\$;

CREATE OR REPLACE FUNCTION DAY(dt date)
RETURNS integer LANGUAGE sql IMMUTABLE AS \$\$ SELECT EXTRACT(DAY FROM dt)::integer; \$\$;

-- 4. IFNULL
CREATE OR REPLACE FUNCTION IFNULL(val anyelement, fallback anyelement)
RETURNS anyelement LANGUAGE sql IMMUTABLE AS \$\$ SELECT COALESCE(val, fallback); \$\$;

-- 5. DATE_SUB
CREATE OR REPLACE FUNCTION DATE_SUB(ts timestamp without time zone, iv interval)
RETURNS timestamp without time zone LANGUAGE sql IMMUTABLE AS \$\$ SELECT ts - iv; \$\$;

CREATE OR REPLACE FUNCTION DATE_SUB(ts timestamptz, iv interval)
RETURNS timestamptz LANGUAGE sql IMMUTABLE AS \$\$ SELECT ts - iv; \$\$;

CREATE OR REPLACE FUNCTION DATE_SUB(dt date, iv interval)
RETURNS date LANGUAGE sql IMMUTABLE AS \$\$ SELECT (dt - iv)::date; \$\$;

-- 6. DATE_ADD
CREATE OR REPLACE FUNCTION DATE_ADD(ts timestamp without time zone, iv interval)
RETURNS timestamp without time zone LANGUAGE sql IMMUTABLE AS \$\$ SELECT ts + iv; \$\$;

CREATE OR REPLACE FUNCTION DATE_ADD(ts timestamptz, iv interval)
RETURNS timestamptz LANGUAGE sql IMMUTABLE AS \$\$ SELECT ts + iv; \$\$;

CREATE OR REPLACE FUNCTION DATE_ADD(dt date, iv interval)
RETURNS date LANGUAGE sql IMMUTABLE AS \$\$ SELECT (dt + iv)::date; \$\$;

-- 7. DATE_FORMAT
CREATE OR REPLACE FUNCTION DATE_FORMAT(ts timestamp without time zone, fmt text)
RETURNS text LANGUAGE plpgsql IMMUTABLE AS \$\$
DECLARE
    pg_fmt text := fmt;
BEGIN
    pg_fmt := replace(pg_fmt, '%Y', 'YYYY');
    pg_fmt := replace(pg_fmt, '%m', 'MM');
    pg_fmt := replace(pg_fmt, '%d', 'DD');
    pg_fmt := replace(pg_fmt, '%H', 'HH24');
    pg_fmt := replace(pg_fmt, '%i', 'MI');
    pg_fmt := replace(pg_fmt, '%s', 'SS');
    RETURN to_char(ts, pg_fmt);
END;
\$\$;

CREATE OR REPLACE FUNCTION DATE_FORMAT(ts timestamptz, fmt text)
RETURNS text LANGUAGE plpgsql IMMUTABLE AS \$\$
DECLARE
    pg_fmt text := fmt;
BEGIN
    pg_fmt := replace(pg_fmt, '%Y', 'YYYY');
    pg_fmt := replace(pg_fmt, '%m', 'MM');
    pg_fmt := replace(pg_fmt, '%d', 'DD');
    pg_fmt := replace(pg_fmt, '%H', 'HH24');
    pg_fmt := replace(pg_fmt, '%i', 'MI');
    pg_fmt := replace(pg_fmt, '%s', 'SS');
    RETURN to_char(ts, pg_fmt);
END;
\$\$;

CREATE OR REPLACE FUNCTION DATE_FORMAT(dt date, fmt text)
RETURNS text LANGUAGE plpgsql IMMUTABLE AS \$\$
DECLARE
    pg_fmt text := fmt;
BEGIN
    pg_fmt := replace(pg_fmt, '%Y', 'YYYY');
    pg_fmt := replace(pg_fmt, '%m', 'MM');
    pg_fmt := replace(pg_fmt, '%d', 'DD');
    RETURN to_char(dt, pg_fmt);
END;
\$\$;
";

$pdo->exec($sql);
echo "✓ Fonctions de compatibilité installées avec succès dans PostgreSQL.\n";
