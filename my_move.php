<?php
/**
 * Me vs the World Chess — Host Move Page (Token-Gated)
 *
 * Intent:
 * - This page is for the host to play their move when notified by email.
 * - Access is granted via a single-use token included in the email link.
 * - No CAPTCHA is required here because the token is the security boundary.
 *
 * High-level flow:
 * 1) Host visits /my_move.php?token=...
 * 2) Page loads current state from /api/state.php for display.
 * 3) Host makes a move (client-side chess.js legality checks).
 * 4) Submit to /api/my_move_submit.php along with token, updated FEN/PGN, and move notation.
 *
 * Security model:
 * - Server must validate token: exists, purpose, not used, not expired.
 * - Server must validate it is the host's turn and game is active.
 *
 * Game lifecycle:
 * - Host may optionally end a game (checkmate/stalemate/resign workflow later)
 * - Ending a game starts a new one and flips host color for the next game.
 */

