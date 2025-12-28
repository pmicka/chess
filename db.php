<?php
/**
 * db.php — Database access helper (SQLite via PDO)
 *
 * Intent:
 * - Provide a single, reliable PDO connection to the SQLite database.
 * - Keep database location configurable via DB_PATH (from config.php).
 *
 * Responsibilities:
 * - Load config.php
 * - Create PDO connection
 * - Enable foreign keys (SQLite PRAGMA)
 * - Set sensible error mode (exceptions)
 *
 * Non-responsibilities:
 * - No schema creation here (see init_db.php)
 * - No business logic here (endpoints handle game rules)
 */

