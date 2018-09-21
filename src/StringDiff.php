<?hh // strict
/*
 *  Copyright (c) 2017-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */


namespace Facebook\DiffLib;

use namespace HH\Lib\{C, Str, Vec};

/** Concrete instance of `Diff` for comparing sequences of strings.
 *
 * You can directly pass in vectors of strings to the constructor, or you can
 * use `::lines()` or `::characters()` for convenience in the common cases.
 *
 * @see `getDiff()` to get a `vec<DiffOp<string>>`
 * @see `getUnifiedDiff()` to get a diff suitable for `patch`
 * @see `CLIColoredUnifiedDiff` if you want to provide a human-readable diff on
 *   a terminal.
 */
final class StringDiff extends Diff {
  const type TContent = string;

  public static function lines(string $a, string $b): this {
    return new self(Str\split($a, "\n"), Str\split($b, "\n"));
  }

  public static function characters(string $a, string $b): this {
    return new self(Str\split($a, ''), Str\split($b, ''));
  }

  public function getUnifiedDiff(int $context = 3): string {
    $hunks = vec[];

    $remaining = $this->getDiff();
    $last = C\lastx($remaining);
    // diff -u ignores trailing newlines
    if ($last is DiffKeepOp<_> && $last->getContent() === '') {
      $remaining = Vec\slice($remaining, 0, C\count($remaining) - 1);
    }

    while (!C\is_empty($remaining)) {
      $not_keep = C\find_key($remaining, $row ==> !$row instanceof DiffKeepOp);
      if ($not_keep === null) {
        break;
      }
      $start = ($not_keep > $context) ? ($not_keep - $context) : 0;

      $remaining = Vec\drop($remaining, $start);
      $count = C\count($remaining);

      $end = $count;
      $run_start = null;
      for ($i = $context; $i < $count; ++$i) {
        if ($remaining[$i] instanceof DiffKeepOp) {
          $run_start = $run_start ?? $i;
          continue;
        }

        if ($run_start === null) {
          continue;
        }

        if ($i >= $run_start + (2 * $context)) {
          $end = $run_start + $context;
          break;
        }
      }
      if ($run_start !== null) {
        $end = $run_start + $context;
      }
      $hunks[] = Vec\take($remaining, $end);
      $remaining = Vec\drop($remaining, $end);
    }

    return Vec\map($hunks, $hunk ==> $this->getUnifiedDiffHunk($hunk))
      |> Vec\filter_nulls($$)
      |> Str\join($$, "");
  }

  private function getUnifiedDiffHunk(
    vec<DiffOp<string>> $hunk,
  ): ?string {
    if (C\is_empty($hunk)) {
      return null;
    }
    $old_start = null;
    $new_start = null;
    $old_lines = 0;
    $new_lines = 0;

    $lines = vec[];

    foreach ($hunk as $op) {
      if ($op instanceof DiffKeepOp) {
        $lines[] = ' '.$op->getContent();
        $old_start = $old_start ?? $op->getOldPos();
        $new_start = $new_start ?? $op->getNewPos();
        ++$old_lines;
        ++$new_lines;
        continue;
      }

      if ($op instanceof DiffDeleteOp) {
        $lines[] = '-'.$op->getContent();
        $old_start = $old_start ?? $op->getOldPos();
        $new_start = $new_start ?? $op->getOldPos();
        ++$old_lines;
        continue;
      }

      if ($op instanceof DiffInsertOp) {
        $lines[] = '+'.$op->getContent();
        $old_start = $old_start ?? $op->getNewPos();
        $new_start = $new_start ?? $op->getNewPos();
        ++$new_lines;
        continue;
      }

      invariant_violation(
        'Unsupported diff op: %s',
        \get_class($op),
      );
    }
    invariant($old_start !== null, 'failed to find an old pos');
    invariant($new_start !== null, 'failed to find a new pos');

    return Str\format(
      "@@ -%d,%d +%d,%d @@\n",
      $old_start + 1,
      $old_lines,
      $new_start + 1,
      $new_lines,
    ).Str\join($lines, "\n")."\n";
  }
}
