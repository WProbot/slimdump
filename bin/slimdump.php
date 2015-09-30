<?php

use  \Webfactory\Slimdump\Config\Config;

$possibleAutoloadFiles = array(
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php'
);
foreach ($possibleAutoloadFiles as $autoloadFile) {
    if (file_exists($autoloadFile)) {
        require_once $autoloadFile;
    }
}

array_shift($_SERVER['argv']);

if (!$_SERVER['argv']) {
    fail("Usage: slimdump {DSN} {config.xml ...}");
}

$db = connect(array_shift($_SERVER['argv']));

print "SET NAMES utf8;\n";
print "SET FOREIGN_KEY_CHECKS = 0;\n\n";

$config = new Config();

while ($argv = array_shift($_SERVER['argv'])) {
    $config->load($argv);
}

processConfig($config, $db);
/**
 * @param string $file
 * @param Zend_Db_Adapter_Abstract $db
 */
function processConfig(Config $config, $db)
{
    foreach ($db->listTables() as $table) {
        $tableConfig = $config->findTable($table);

        if (null === $tableConfig) {
            continue;
        }

        $dumpType = $tableConfig->getDump();

        if ($dumpType >= Config::SCHEMA) {
            dumpSchema($table, $db);
        }

        if ($dumpType >= Config::NOBLOB) {
            dumpData($table, $db, $dumpType == Config::NOBLOB);
        }
    }
}

/**
 * @param string $table
 * @param Zend_Db_Adapter_Abstract $db
 */
function dumpSchema($table, $db)
{
    print "-- BEGIN STRUCTURE $table \n";
    print "DROP TABLE IF EXISTS `$table`;\n";
    print $db->query("SHOW CREATE TABLE `$table`")->fetchColumn(1) . ";\n\n";
}

/**
 * @param string $table
 * @param Zend_Db_Adapter_Abstract $db
 * @return array
 */
function cols($table, $db)
{
    $c = array();
    foreach ($db->fetchAll("SHOW COLUMNS FROM `$table`") as $row) {
        $c[$row['Field']] = $row['Type'];
    }
    return $c;
}

/**
 * @param string $table
 * @param array(string=>mixed) $cols
 * @return string
 */
function insertValuesStatement($table, $cols)
{
    return "INSERT INTO `$table` (`" . implode(array_keys($cols), '`, `') . "`) VALUES ";
}

function isBlob($col, $definitions)
{
    return stripos($definitions[$col], 'blob') !== false;
}

function rowLengthEstimate($row)
{
    $l = 0;
    foreach ($row as $value) {
        $l += strlen($value);
    }
    return $l;
}

/**
 * @param string $table
 * @param Zend_Db_Adapter_Abstract $db
 * @param bool $nullBlob
 */
function dumpData($table, $db, $nullBlob = false)
{
    $cols = cols($table, $db);

    $s = "SELECT ";
    $first = true;
    foreach (array_keys($cols) as $name) {
        if (!$first) {
            $s .= ', ';
        }
        if (isBlob($name, $cols)) {
            if ($nullBlob) {
                $s .= "NULL";
            } else {
                $s .= "IF(ISNULL(`$name`), 'NULL', IF(`$name`='', '\"\"', CONCAT('0x', HEX(`$name`))))";
            }
            $s .= " AS `$name`";
        } else {
            $s .= "`$name`";
        }
        $first = false;
    }
    $s .= " FROM `$table`";

    print "-- BEGIN DATA $table \n";

    $bufferSize = 0;
    $max = 100 * 1024 * 1024; // 100 MB
    $numRows = $db->fetchOne("SELECT COUNT(*) FROM $table");
    $count = 0;

    $db->getConnection()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

    foreach ($db->query($s) as $row) {

        fprintf(STDERR, "\rDumping $table: %3u%%", (100 * ++$count) / $numRows);

        $b = rowLengthEstimate($row);

        // Start a new statement to ensure that the line does not get too long.
        if ($bufferSize && $bufferSize + $b > $max) {
            print ";\n";
            $bufferSize = 0;
        }

        if ($bufferSize == 0) {
            print insertValuesStatement($table, $cols);
        } else {
            print ",";
        }

        $firstCol = true;
        print "\n(";

        foreach ($row as $name => $value) {
            if (!$firstCol) {
                print ", ";
            }
            if ($value === null) {
                print "NULL";
            } else {
                if (isBlob($name, $cols)) {
                    print $value;
                } else {
                    print $db->quote($value);
                }
            }
            $firstCol = false;
        }
        print ")";
        $bufferSize += $b;
    }

    $db->getConnection()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    if ($bufferSize) {
        print ";\n";
    }

    fputs(STDERR, "\n");

    print "\n";
}

print "\nSET FOREIGN_KEY_CHECKS = 1;\n";

