#!/usr/bin/env node
'use strict';
// Diagnostic compatibility entrypoint. FEN alone cannot establish repetition.
// Runtime move/transition requests use chess_rules.js with verified PGN history.
const { evaluate } = require('./chess_rules.js');
process.stdout.write(JSON.stringify(evaluate({fen:process.argv[2] || ''})) + '\n');
