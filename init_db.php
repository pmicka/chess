<?php
/**
 * init_db.php — One-time database bootstrap (CLI-friendly)
 *
 * Intent:
 * - Create required SQLite tables if they do not exist.
 * - Insert an initial active game if none exists.
 *
 * Tables (typical):
 * - games: current game state (FEN/PGN/turn/status), and host color for that game
 * - tokens: single-use tokens used to authorize host moves (emailed links)
 * - locks: lightweight mechanism to ensure only one visitor move is accepted per turn
 *
 * Game rules enforced at a data level:
 * - There is exactly one "active" game at a time in MVP.
 * - Host color flips per new game (new game uses opposite host color from prior game).
 *
 * Usage:
 * - Run from shell: php init_db.php
 * - Should print a clear success/failure message.
 */

