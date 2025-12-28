<?php
/**
 * /api/my_move_submit.php — Accept a host move (token-gated)
 *
 * Intent:
 * - Allow the host to submit their move using a single-use token.
 * - Update game state, flip turn back to visitors.
 *
 * Required checks (server-side):
 * - Token MUST exist, match purpose, not be used, not be expired.
 * - Game MUST be active and must match token's game_id.
 * - It MUST be the host's turn.
 *
 * Data accepted (MVP):
 * - token
 * - updated FEN/PGN
 * - move notation (SAN or coordinate)
 *
 * Optional behavior:
 * - "End game" action (later: checkmate/stalemate/resign) creates a new game
 *   and flips host color for the next game.
 *
 * Security:
 * - No CAPTCHA required here; the token is the gate.
 * - Token should be marked used immediately on success.
 */

