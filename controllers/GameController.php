<?php

require_once '../models/Game.php';
require_once '../core/Session.php';

class GameController
{
    private $pdo;
    private $gameModel;
    private $chatModel;

    public function __construct()
    {
        $config = require '../config.php';

        $this->pdo = new PDO(
            "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
            $config['db']['user'],
            $config['db']['pass']
        );
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->gameModel = new Game($this->pdo);

        Session::start();
    }

    public function getChatMessages($game_id)
    {
        return $this->chatModel->getMessages($game_id);
    }

    public function sendMessage()
    {
        if (!$this->isUserLoggedIn()) {
            return $this->redirectToLogin();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty(trim($_POST['message']))) {
            $chat = Session::get('chat') ?? [];
            $chat[] = [
                'user' => Session::get('username'),
                'message' => trim($_POST['message']),
                'timestamp' => time()
            ];
            Session::set('chat', $chat);
        }

        $this->redirect('play');
    }

    public function clearChat()
    {
        if (!$this->isUserLoggedIn()) {
            return $this->redirectToLogin();
        }

        Session::set('chat', []);
        $this->redirect('play');
    }

    public function stats()
    {
        if (!$this->isUserLoggedIn()) {
            return $this->redirectToLogin();
        }

        $stats = $this->gameModel->getUserStats(Session::get('user_id'));
        include '../views/game/stats.php';
    }

    public function start()
    {
        if (!$this->isUserLoggedIn()) {
            return $this->redirectToLogin();
        }

        Session::set('chat', []);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $mode = $_POST['mode'];
            $size = (int)$_POST['size'];
            $difficulty = $_POST['difficulty'] ?? 'easy';
            $playerSymbol = $_POST['player_symbol'] ?? 'X';
            $botSymbol = $playerSymbol === 'X' ? 'O' : '#';

            Session::setMultiple([
                'mode' => $mode,
                'board_size' => $size,
                'difficulty' => $difficulty,
                'player_symbol' => $playerSymbol,
                'bot_symbol' => $botSymbol,
                'board' => array_fill(0, $size, array_fill(0, $size, '')),
                'current_turn' => $playerSymbol,
                'turn_start_time' => time()
            ]);

            $this->redirect('play');
        }

        include '../views/game/start.php';
    }

    public function play()
    {
        if (!$this->isUserLoggedIn()) {
            return $this->redirectToLogin();
        }

        $board = Session::get('board');
        $turn = Session::get('current_turn');
        $mode = Session::get('mode');
        $size = Session::get('board_size');
        $playerSymbol = Session::get('player_symbol', 'X');
        $botSymbol = Session::get('bot_symbol', 'O');

        if ((time() - Session::get('turn_start_time')) > 10) {
            if ($mode === 'bot' && $turn === $playerSymbol) {
                $this->endGame(null, 'Час на хід вичерпано! Бот переміг!');
            } else {
                Session::set('current_turn', $turn === $playerSymbol ? $botSymbol : $playerSymbol);
                Session::set('turn_start_time', time());
                Session::set('message', 'Час на хід вичерпано! Хід передано.');
                $this->redirect('play');
            }
        }

        if (isset($_GET['row'], $_GET['col'])) {
            $this->handlePlayerMove((int)$_GET['row'], (int)$_GET['col']);
        }

        include '../views/game/board.php';
    }

    public function result()
    {
        include '../views/game/result.php';
    }

    private function isUserLoggedIn(): bool
    {
        return Session::get('user_id') !== null;
    }

    private function redirectToLogin()
    {
        $this->redirect('login');
    }

    private function redirect(string $action)
    {
        header("Location: index.php?action={$action}");
        exit;
    }

    private function endGame(?int $winnerId, string $message)
    {
        $this->gameModel->saveResult(Session::get('user_id'), null, $winnerId, Session::get('board_size'));
        Session::set('message', $message);
        $this->redirect('result');
    }

    private function handlePlayerMove(int $row, int $col)
    {
        $board = Session::get('board');
        $turn = Session::get('current_turn');
        $mode = Session::get('mode');
        $size = Session::get('board_size');
        $playerSymbol = Session::get('player_symbol');
        $botSymbol = Session::get('bot_symbol');

        if ($board[$row][$col] === '') {
            $board[$row][$col] = $turn;
            Session::set('board', $board);
            Session::set('turn_start_time', time());

            if ($this->checkWin($board, $turn, $size)) {
                $this->endGame($turn === $playerSymbol ? Session::get('user_id') : null, "$turn переміг!");
            } elseif ($this->checkDraw($board)) {
                $this->endGame(null, "Нічия!");
            }

            if ($mode === 'bot' && $turn === $playerSymbol) {
                $this->botMove();
                $board = Session::get('board');

                if ($this->checkWin($board, $botSymbol, $size)) {
                    $this->endGame(null, "Бот переміг!");
                } elseif ($this->checkDraw($board)) {
                    $this->endGame(null, "Нічия!");
                }

                Session::set('current_turn', $playerSymbol);
            } else {
                Session::set('current_turn', $turn === $playerSymbol ? $botSymbol : $playerSymbol);
            }
        }
    }

    private function checkWin(array $board, string $symbol, int $size): bool
    {
        for ($i = 0; $i < $size; $i++) {
            if (count(array_unique($board[$i])) === 1 && $board[$i][0] === $symbol) return true;
            if (count(array_unique(array_column($board, $i))) === 1 && $board[0][$i] === $symbol) return true;
        }

        return $this->checkDiagonals($board, $symbol, $size);
    }

    private function checkDiagonals(array $board, string $symbol, int $size): bool
    {
        $diag1 = $diag2 = true;
        for ($i = 0; $i < $size; $i++) {
            if ($board[$i][$i] !== $symbol) $diag1 = false;
            if ($board[$i][$size - $i - 1] !== $symbol) $diag2 = false;
        }
        return $diag1 || $diag2;
    }

    private function checkDraw(array $board): bool
    {
        foreach ($board as $row) {
            if (in_array('', $row)) return false;
        }
        return true;
    }

    private function botMove()
    {
        $board = Session::get('board');
        $size = Session::get('board_size');
        $difficulty = Session::get('difficulty', 'easy');
        $botSymbol = Session::get('bot_symbol');
        $userSymbol = Session::get('player_symbol');

        switch ($difficulty) {
            case 'medium':
                $move = $this->mediumAI($board, $botSymbol, $userSymbol);
                break;
            case 'hard':
                $move = $this->minimaxMove($board, $botSymbol)['move'];
                break;
            default:
                $move = $this->firstAvailableMove($board);
        }

        if ($move) {
            [$i, $j] = $move;
            $board[$i][$j] = $botSymbol;
            Session::set('board', $board);
        }
    }

    private function firstAvailableMove(array $board): ?array
    {
        foreach ($board as $i => $row) {
            foreach ($row as $j => $cell) {
                if ($cell === '') return [$i, $j];
            }
        }
        return null;
    }

    private function mediumAI(array $board, string $botSymbol, string $userSymbol): ?array
    {
        $size = count($board);
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                if ($board[$i][$j] === '') {
                    $board[$i][$j] = $userSymbol;
                    if ($this->checkWin($board, $userSymbol, $size)) {
                        return [$i, $j];
                    }
                    $board[$i][$j] = '';
                }
            }
        }
        return $this->firstAvailableMove($board);
    }

    private function minimaxMove(array $board, string $player): array
    {
        $opponent = $player === 'X' ? 'O' : 'X';
        $bestScore = -INF;
        $bestMove = null;

        foreach ($this->getAvailableMoves($board) as [$i, $j]) {
            $board[$i][$j] = $player;
            $score = $this->minimax($board, false, $player, $opponent);
            $board[$i][$j] = '';
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMove = [$i, $j];
            }
        }

        return ['move' => $bestMove, 'score' => $bestScore];
    }

    private function minimax(array $board, bool $isMax, string $player, string $opponent): int
    {
        $size = count($board);

        if ($this->checkWin($board, $player, $size)) return 1;
        if ($this->checkWin($board, $opponent, $size)) return -1;
        if (empty($this->getAvailableMoves($board))) return 0;

        $best = $isMax ? -INF : INF;

        foreach ($this->getAvailableMoves($board) as [$i, $j]) {
            $board[$i][$j] = $isMax ? $player : $opponent;
            $score = $this->minimax($board, !$isMax, $player, $opponent);
            $board[$i][$j] = '';
            $best = $isMax ? max($best, $score) : min($best, $score);
        }

        return $best;
    }

    private function getAvailableMoves(array $board): array
    {
        $moves = [];
        foreach ($board as $i => $row) {
            foreach ($row as $j => $cell) {
                if ($cell === '') {
                    $moves[] = [$i, $j];
                }
            }
        }
        return $moves;
    }
}
?>

