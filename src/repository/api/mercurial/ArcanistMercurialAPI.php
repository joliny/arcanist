<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Interfaces with the Mercurial working copies.
 *
 * @group workingcopy
 */
class ArcanistMercurialAPI extends ArcanistRepositoryAPI {

  private $status;
  private $base;
  private $relativeCommit;

  public function getSourceControlSystemName() {
    return 'hg';
  }

  public function getSourceControlBaseRevision() {
    list($stdout) = execx(
      '(cd %s && hg id -ir %s)',
      $this->getPath(),
      $this->getRelativeCommit());
    return $stdout;
  }

  public function getSourceControlPath() {
    return '/';
  }

  public function getBranchName() {
    // TODO: I have nearly no idea how hg local branches work.
    list($stdout) = execx(
      '(cd %s && hg branch)',
      $this->getPath());
    return $stdout;
  }

  public function setRelativeCommit($commit) {
    list($err) = exec_manual(
      '(cd %s && hg id -ir %s)',
      $this->getPath(),
      $commit);

    if ($err) {
      throw new ArcanistUsageException(
        "Commit '{$commit}' is not a valid Mercurial commit identifier.");
    }

    $this->relativeCommit = $commit;
    return $this;
  }

  public function getRelativeCommit() {
    if (empty($this->relativeCommit)) {
      list($stdout) = execx(
        '(cd %s && hg outgoing --branch `hg branch` --limit 1 --style default)',
        $this->getPath());
      $logs = $this->parseMercurialLog($stdout);
      if (!count($logs)) {
        throw new ArcanistUsageException("You have no outgoing changes!");
      }
      $oldest_log = head($logs);
      $oldest_rev = $oldest_log['rev'];

      // NOTE: The "^" and "~" syntaxes were only added in hg 1.9, which is new
      // as of July 2011, so do this in a compatible way. Also, "hg log" and
      // "hg outgoing" don't necessarily show parents (even if given an explicit
      // template consisting of just the parents token) so we need to separately
      // execute "hg parents".

      list($stdout) = execx(
        '(cd %s && hg parents --style default --rev %s)',
        $this->getPath(),
        $oldest_rev);
      $parents_logs = $this->parseMercurialLog($stdout);
      $first_parent = head($parents_logs);
      if (!$first_parent) {
        throw new ArcanistUsageException(
          "Oldest outgoing change has no parent revision!");
      }

      $this->relativeCommit = $first_parent['rev'];
    }
    return $this->relativeCommit;
  }

  public function getLocalCommitInformation() {
    list($info) = execx(
      '(cd %s && hg log --style default --rev %s..%s --)',
      $this->getPath(),
      $this->getRelativeCommit(),
      $this->getWorkingCopyRevision());
    $logs = $this->parseMercurialLog($info);

    // Get rid of the first log, it's not actually part of the diff. "hg log"
    // is inclusive, while "hg diff" is exclusive.
    array_shift($logs);

    return $logs;
  }


  public function getBlame($path) {
    list($stdout) = execx(
      '(cd %s && hg blame -u -v -c --rev %s -- %s)',
      $this->getPath(),
      $this->getRelativeCommit(),
      $path);

    $blame = array();
    foreach (explode("\n", trim($stdout)) as $line) {
      if (!strlen($line)) {
        continue;
      }

      $matches = null;
      $ok = preg_match('^/\s*([^:]+?) [a-f0-9]{12}: (.*)$/', $line, $matches);

      if (!$ok) {
        throw new Exception("Unable to parse Mercurial blame line: {$line}");
      }

      $revision = $matches[2];
      $author = trim($matches[1]);
      $blame[] = array($author, $revision);
    }

    return $blame;
  }

  public function getWorkingCopyStatus() {

    if (!isset($this->status)) {
      // A reviewable revision spans multiple local commits in Mercurial, but
      // there is no way to get file change status across multiple commits, so
      // just take the entire diff and parse it to figure out what's changed.

      $diff = $this->getFullMercurialDiff();
      $parser = new ArcanistDiffParser();
      $changes = $parser->parseDiff($diff);

      $status_map = array();

      foreach ($changes as $change) {
        $flags = 0;
        switch ($change->getType()) {
          case ArcanistDiffChangeType::TYPE_ADD:
          case ArcanistDiffChangeType::TYPE_MOVE_HERE:
          case ArcanistDiffChangeType::TYPE_COPY_HERE:
            $flags |= self::FLAG_ADDED;
            break;
          case ArcanistDiffChangeType::TYPE_CHANGE:
          case ArcanistDiffChangeType::TYPE_COPY_AWAY: // Check for changes?
            $flags |= self::FLAG_MODIFIED;
            break;
          case ArcanistDiffChangeType::TYPE_DELETE:
          case ArcanistDiffChangeType::TYPE_MOVE_AWAY:
          case ArcanistDiffChangeType::TYPE_MULTICOPY:
            $flags |= self::FLAG_DELETED;
            break;
        }
        $status_map[$change->getCurrentPath()] = $flags;
      }

      list($stdout) = execx(
        '(cd %s && hg status)',
        $this->getPath());

      $working_status = $this->parseMercurialStatus($stdout);
      foreach ($working_status as $path => $status) {
        $status |= self::FLAG_UNCOMMITTED;
        if (!empty($status_map[$path])) {
          $status_map[$path] |= $status;
        } else {
          $status_map[$path] = $status;
        }
      }

      $this->status = $status_map;
    }

    return $this->status;
  }

  private function getDiffOptions() {
    $options = array(
      '--git',
      '-U'.$this->getDiffLinesOfContext(),
    );
    return implode(' ', $options);
  }

  public function getRawDiffText($path) {
    $options = $this->getDiffOptions();

    list($stdout) = execx(
      '(cd %s && hg diff %C --rev %s --rev %s -- %s)',
      $this->getPath(),
      $options,
      $this->getRelativeCommit(),
      $this->getWorkingCopyRevision(),
      $path);

    return $stdout;
  }

  public function getFullMercurialDiff() {
    $options = $this->getDiffOptions();

    list($stdout) = execx(
      '(cd %s && hg diff %C --rev %s --rev %s --)',
      $this->getPath(),
      $options,
      $this->getRelativeCommit(),
      $this->getWorkingCopyRevision());

    return $stdout;
  }

  public function getOriginalFileData($path) {
    return $this->getFileDataAtRevision($path, $this->getRelativeCommit());
  }

  public function getCurrentFileData($path) {
    return $this->getFileDataAtRevision(
      $path,
      $this->getWorkingCopyRevision());
  }

  private function getFileDataAtRevision($path, $revision) {
    list($stdout) = execx(
      '(cd %s && hg cat --rev %s -- %s)',
      $this->getPath(),
      $path);
    return $stdout;
  }

  private function parseMercurialStatus($status) {
    $result = array();

    $status = trim($status);
    if (!strlen($status)) {
      return $result;
    }

    $lines = explode("\n", $status);
    foreach ($lines as $line) {
      $flags = 0;
      list($code, $path) = explode(' ', $line, 2);
      switch ($code) {
        case 'A':
          $flags |= self::FLAG_ADDED;
          break;
        case 'R':
          $flags |= self::FLAG_REMOVED;
          break;
        case 'M':
          $flags |= self::FLAG_MODIFIED;
          break;
        case 'C':
          // This is "clean" and included only for completeness, these files
          // have not been changed.
          break;
        case '!':
          $flags |= self::FLAG_MISSING;
          break;
        case '?':
          $flags |= self::FLAG_UNTRACKED;
          break;
        case 'I':
          // This is "ignored" and included only for completeness.
          break;
        default:
          throw new Exception("Unknown Mercurial status '{$code}'.");
      }

      $result[$path] = $flags;
    }

    return $result;
  }

  private function parseMercurialLog($log) {
    $result = array();

    $chunks = explode("\n\n", trim($log));
    foreach ($chunks as $chunk) {
      $commit = array();
      $lines = explode("\n", $chunk);
      foreach ($lines as $line) {
        if (preg_match('/^(comparing with|searching for changes)/', $line)) {
          // These are sent to stdout when you run "hg outgoing" although the
          // format is otherwise identical to "hg log".
          continue;
        }
        list($name, $value) = explode(':', $line, 2);
        $value = trim($value);
        switch ($name) {
          case 'user':
            $commit['user'] = $value;
            break;
          case 'date':
            $commit['date'] = strtotime($value);
            break;
          case 'summary':
            $commit['summary'] = $value;
            break;
          case 'changeset':
            list($local, $rev) = explode(':', $value, 2);
            $commit['local'] = $local;
            $commit['rev'] = $rev;
            break;
          case 'parent':
            if (empty($commit['parents'])) {
              $commit['parents'] = array();
            }
            list($local, $rev) = explode(':', $value, 2);
            $commit['parents'][] = array(
              'local' => $local,
              'rev'   => $rev,
            );
            break;
          case 'branch':
            $commit['branch'] = $value;
            break;
          case 'tag':
            $commit['tag'] = $value;
            break;
          default:
            throw new Exception("Unknown Mercurial log field '{$name}'!");
        }
      }
      $result[] = $commit;
    }

    return $result;
  }

  private function getWorkingCopyRevision() {
    // In Mercurial, "tip" means the tip of the current branch, not what's in
    // the working copy. The tip may be ahead of the working copy. We need to
    // use "hg summary" to figure out what is actually in the working copy.
    // For instance, "hg up 4 && arc diff" should not show commits 5 and above.

    // The output of "hg summary" is different from the output of other hg
    // commands so just parse it manually.
    list($stdout) = execx(
      '(cd %s && hg summary)',
      $this->getPath());
    $lines = explode("\n", $stdout);

    $first = head($lines);
    $match = null;
    if (!preg_match('/^parent: \d+:([^ ]+)( |$)/', $first, $match)) {
      throw new Exception("Unable to parse 'hg summary'.");
    }

    return trim($match[1]);
  }

  public function supportsRelativeLocalCommits() {
    return true;
  }

  public function parseRelativeLocalCommit(array $argv) {
    if (count($argv) == 0) {
      return;
    }
    if (count($argv) != 1) {
      throw new ArcanistUsageException("Specify only one commit.");
    }
    // This does the "hg id" call we need to normalize/validate the revision
    // identifier.
    $this->setRelativeCommit(reset($argv));
  }

  public function getAllLocalChanges() {
    $diff = $this->getFullMercurialDiff();
    $parser = new ArcanistDiffParser();
    return $parser->parseDiff($diff);
  }

  public function supportsLocalBranchMerge() {
    return true;
  }

  public function performLocalBranchMerge($branch, $message) {
    if ($branch) {
      $err = phutil_passthru(
        '(cd %s && hg merge --rev %s && hg commit -m %s)',
        $this->getPath(),
        $branch,
        $message);
    } else {
      $err = phutil_passthru(
        '(cd %s && hg merge && hg commit -m %s)',
        $this->getPath(),
        $message);
    }

    if ($err) {
      throw new ArcanistUsageException("Merge failed!");
    }
  }

  public function getFinalizedRevisionMessage() {
    return "You may now push this commit upstream, as appropriate (e.g. with ".
           "'hg push' or by printing and faxing it).";
  }

}