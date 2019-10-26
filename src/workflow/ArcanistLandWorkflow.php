<?php

/**
 * Lands a branch by rebasing, merging and amending it.
 */
final class ArcanistLandWorkflow extends ArcanistWorkflow {

  private $isGit;
  private $isGitSvn;
  private $isHg;
  private $isHgSvn;

  private $oldBranch;
  private $branch;
  private $onto;
  private $ontoRemoteBranch;
  private $remote;
  private $useSquash;
  private $keepBranch;
  private $branchType;
  private $ontoType;
  private $preview;

  private $revision;
  private $messageFile;

  const REFTYPE_BRANCH = 'branch';
  const REFTYPE_BOOKMARK = 'bookmark';

  public function getRevisionDict() {
    return $this->revision;
  }

  public function getWorkflowName() {
    return 'land';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **land** [__options__] [__ref__]
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git, hg

          Publish an accepted revision after review. This command is the last
          step in the standard Differential pre-publish code review workflow.

          This command merges and pushes changes associated with an accepted
          revision that are currently sitting in __ref__, which is usually the
          name of a local branch. Without __ref__, the current working copy
          state will be used.

          Under Git: branches, tags, and arbitrary commits (detached HEADs)
          may be landed.

          Under Mercurial: branches and bookmarks may be landed, but only
          onto a target of the same type. See T3855.

          The workflow selects a target branch to land onto and a remote where
          the change will be pushed to.

          A target branch is selected by examining these sources in order:

            - the **--onto** flag;
            - the upstream of the current branch, recursively (Git only);
            - the __arc.land.onto.default__ configuration setting;
            - or by falling back to a standard default:
              - "master" in Git;
              - "default" in Mercurial.

          A remote is selected by examining these sources in order:

            - the **--remote** flag;
            - the upstream of the current branch, recursively (Git only);
            - or by falling back to a standard default:
              - "origin" in Git;
              - the default remote in Mercurial.

          After selecting a target branch and a remote, the commits which will
          be landed are printed.

          With **--preview**, execution stops here, before the change is
          merged.

          The change is merged with the changes in the target branch,
          following these rules:

          In repositories with mutable history or with **--squash**, this will
          perform a squash merge (the entire branch will be represented as one
          commit after the merge).

          In repositories with immutable history or with **--merge**, this will
          perform a strict merge (a merge commit will always be created, and
          local commits will be preserved).

          The resulting commit will be given an up-to-date commit message
          describing the final state of the revision in Differential.

          In Git, the merge occurs in a detached HEAD. The local branch
          reference (if one exists) is not updated yet.

          With **--hold**, execution stops here, before the change is pushed.

          The change is pushed into the remote.

          Consulting mystical sources of power, the workflow makes a guess
          about what state you wanted to end up in after the process finishes
          and the working copy is put into that state.

          The branch which was landed is deleted, unless the **--keep-branch**
          flag was passed or the landing branch is the same as the target
          branch.

EOTEXT
      );
  }

  public function requiresWorkingCopy() {
    return true;
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function getArguments() {
    return array(
      'onto' => array(
        'param' => 'master',
        'help' => pht(
          "Land feature branch onto a branch other than the default ".
          "('master' in git, 'default' in hg). You can change the default ".
          "by setting '%s' with `%s` or for the entire project in %s.",
          'arc.land.onto.default',
          'arc set-config',
          '.arcconfig'),
      ),
      'hold' => array(
        'help' => pht(
          'Prepare the change to be pushed, but do not actually push it.'),
      ),
      'keep-branch' => array(
        'help' => pht(
          'Keep the feature branch after pushing changes to the '.
          'remote (by default, it is deleted).'),
      ),
      'remote' => array(
        'param' => 'origin',
        'help' => pht(
          "Push to a remote other than the default ('origin' in git)."),
      ),
      'merge' => array(
        'help' => pht(
          'Perform a %s merge, not a %s merge. If the project '.
          'is marked as having an immutable history, this is the default '.
          'behavior.',
          '--no-ff',
          '--squash'),
        'supports' => array(
          'git',
        ),
        'nosupport'   => array(
          'hg' => pht(
            'Use the %s strategy when landing in mercurial.',
            '--squash'),
        ),
      ),
      'squash' => array(
        'help' => pht(
          'Perform a %s merge, not a %s merge. If the project is '.
          'marked as having a mutable history, this is the default behavior.',
          '--squash',
          '--no-ff'),
        'conflicts' => array(
          'merge' => pht(
            '%s and %s are conflicting merge strategies.',
            '--merge',
            '--squash'),
        ),
      ),
      'delete-remote' => array(
        'help' => pht(
          'Delete the feature branch in the remote after landing it.'),
        'conflicts' => array(
          'keep-branch' => true,
        ),
        'supports' => array(
          'hg',
        ),
      ),
      'revision' => array(
        'param' => 'id',
        'help' => pht(
          'Use the message from a specific revision, rather than '.
          'inferring the revision based on branch content.'),
      ),
      'preview' => array(
        'help' => pht(
          'Prints the commits that would be landed. Does not '.
          'actually modify or land the commits.'),
      ),
      '*' => 'branch',
    );
  }

  public function run() {
    $this->readArguments();

    $engine = null;
    if ($this->isGit && !$this->isGitSvn) {
      $engine = new ArcanistGitLandEngine();
    }

    if ($engine) {
      $should_hold = $this->getArgument('hold');
      $remote_arg = $this->getArgument('remote');
      $onto_arg = $this->getArgument('onto');

      $engine
        ->setWorkflow($this)
        ->setRepositoryAPI($this->getRepositoryAPI())
        ->setSourceRef($this->branch)
        ->setShouldHold($should_hold)
        ->setShouldKeep($this->keepBranch)
        ->setShouldSquash($this->useSquash)
        ->setShouldPreview($this->preview)
        ->setRemoteArgument($remote_arg)
        ->setOntoArgument($onto_arg)
        ->setBuildMessageCallback(array($this, 'buildEngineMessage'));

      // The goal here is to raise errors with flags early (which is cheap),
      // before we test if the working copy is clean (which can be slow). This
      // could probably be structured more cleanly.

      $engine->parseArguments();

      // This must be configured or we fail later inside "buildEngineMessage()".
      // This is less than ideal.
      $this->ontoRemoteBranch = sprintf(
        '%s/%s',
        $engine->getTargetRemote(),
        $engine->getTargetOnto());

      $this->requireCleanWorkingCopy();
      $engine->execute();

      if (!$should_hold && !$this->preview) {
        $this->didPush();
      }

      return 0;
    }

    $this->validate();

    try {
      $this->pullFromRemote();
    } catch (Exception $ex) {
      $this->restoreBranch();
      throw $ex;
    }

    $this->printPendingCommits();
    if ($this->preview) {
      $this->restoreBranch();
      return 0;
    }

    $this->checkoutBranch();
    $this->findRevision();

    if ($this->useSquash) {
      $this->rebase();
      $this->squash();
    } else {
      $this->merge();
    }

    $this->push();

    if (!$this->keepBranch) {
      $this->cleanupBranch();
    }

    if ($this->oldBranch != $this->onto) {
      // If we were on some branch A and the user ran "arc land B",
      // switch back to A.
      if ($this->keepBranch || $this->oldBranch != $this->branch) {
        $this->restoreBranch();
      }
    }

    echo pht('Done.'), "\n";

    return 0;
  }

  private function getUpstreamMatching($branch, $pattern) {
    if ($this->isGit) {
      $repository_api = $this->getRepositoryAPI();
      list($err, $fullname) = $repository_api->execManualLocal(
        'rev-parse --symbolic-full-name %s@{upstream}',
        $branch);
      if (!$err) {
        $matches = null;
        if (preg_match($pattern, $fullname, $matches)) {
          return last($matches);
        }
      }
    }
    return null;
  }

  private function getGitSvnTrunk() {
    if (!$this->isGitSvn) {
      return null;
    }

    // See T13293, this depends on the options passed when cloning.
    // On any error we return `trunk`, which was the previous default.

    $repository_api = $this->getRepositoryAPI();
    list($err, $refspec) = $repository_api->execManualLocal(
      'config svn-remote.svn.fetch');

    if ($err) {
      return 'trunk';
    }

    $refspec = rtrim(substr($refspec, strrpos($refspec, ':') + 1));

    $prefix = 'refs/remotes/';
    if (substr($refspec, 0, strlen($prefix)) !== $prefix) {
      return 'trunk';
    }

    $refspec = substr($refspec, strlen($prefix));
    return $refspec;
  }

  private function readArguments() {
    $repository_api = $this->getRepositoryAPI();
    $this->isGit = $repository_api instanceof ArcanistGitAPI;
    $this->isHg = $repository_api instanceof ArcanistMercurialAPI;

    if ($this->isGit) {
      $repository = $this->loadProjectRepository();
      $this->isGitSvn = (idx($repository, 'vcs') == 'svn');
    }

    if ($this->isHg) {
      $this->isHgSvn = $repository_api->isHgSubversionRepo();
    }

    $branch = $this->getArgument('branch');
    if (empty($branch)) {
      $branch = $this->getBranchOrBookmark();
      if ($branch !== null) {
        $this->branchType = $this->getBranchType($branch);

        // TODO: This message is misleading when landing a detached head or
        // a tag in Git.

        echo pht("Landing current %s '%s'.", $this->branchType, $branch), "\n";
        $branch = array($branch);
      }
    }

    if (count($branch) !== 1) {
      throw new ArcanistUsageException(
        pht('Specify exactly one branch or bookmark to land changes from.'));
    }
    $this->branch = head($branch);
    $this->keepBranch = $this->getArgument('keep-branch');

    $this->preview = $this->getArgument('preview');

    if (!$this->branchType) {
      $this->branchType = $this->getBranchType($this->branch);
    }

    $onto_default = $this->isGit ? 'master' : 'default';
    $onto_default = nonempty(
      $this->getConfigFromAnySource('arc.land.onto.default'),
      $onto_default);
    $onto_default = coalesce(
      $this->getUpstreamMatching($this->branch, '/^refs\/heads\/(.+)$/'),
      $onto_default);
    $this->onto = $this->getArgument('onto', $onto_default);
    $this->ontoType = $this->getBranchType($this->onto);

    $remote_default = $this->isGit ? 'origin' : '';
    $remote_default = coalesce(
      $this->getUpstreamMatching($this->onto, '/^refs\/remotes\/(.+?)\//'),
      $remote_default);
    $this->remote = $this->getArgument('remote', $remote_default);

    if ($this->getArgument('merge')) {
      $this->useSquash = false;
    } else if ($this->getArgument('squash')) {
      $this->useSquash = true;
    } else {
      $this->useSquash = !$this->isHistoryImmutable();
    }

    $this->ontoRemoteBranch = $this->onto;
    if ($this->isGitSvn) {
      $this->ontoRemoteBranch = $this->getGitSvnTrunk();
    } else if ($this->isGit) {
      $this->ontoRemoteBranch = $this->remote.'/'.$this->onto;
    }

    $this->oldBranch = $this->getBranchOrBookmark();
  }

  private function validate() {
    $repository_api = $this->getRepositoryAPI();

    if ($this->onto == $this->branch) {
      $message = pht(
        "You can not land a %s onto itself -- you are trying ".
        "to land '%s' onto '%s'. For more information on how to push ".
        "changes, see 'Pushing and Closing Revisions' in 'Arcanist User ".
        "Guide: arc diff' in the documentation.",
        $this->branchType,
        $this->branch,
        $this->onto);
      if (!$this->isHistoryImmutable()) {
        $message .= ' '.pht("You may be able to '%s' instead.", 'arc amend');
      }
      throw new ArcanistUsageException($message);
    }

    if ($this->isHg) {
      if ($this->useSquash) {
        if (!$repository_api->supportsRebase()) {
          throw new ArcanistUsageException(
            pht(
              'You must enable the rebase extension to use the %s strategy.',
              '--squash'));
        }
      }

      if ($this->branchType != $this->ontoType) {
        throw new ArcanistUsageException(pht(
          'Source %s is a %s but destination %s is a %s. When landing a '.
          '%s, the destination must also be a %s. Use %s to specify a %s, '.
          'or set %s in %s.',
          $this->branch,
          $this->branchType,
          $this->onto,
          $this->ontoType,
          $this->branchType,
          $this->branchType,
          '--onto',
          $this->branchType,
          'arc.land.onto.default',
          '.arcconfig'));
      }
    }

    if ($this->isGit) {
      list($err) = $repository_api->execManualLocal(
        'rev-parse --verify %s',
        $this->branch);

      if ($err) {
        throw new ArcanistUsageException(
          pht("Branch '%s' does not exist.", $this->branch));
      }
    }

    $this->requireCleanWorkingCopy();
  }

  private function checkoutBranch() {
    $repository_api = $this->getRepositoryAPI();
    if ($this->getBranchOrBookmark() != $this->branch) {
      $repository_api->execxLocal('checkout %s', $this->branch);
    }

    switch ($this->branchType) {
      case self::REFTYPE_BOOKMARK:
        $message = pht(
          'Switched to bookmark **%s**. Identifying and merging...',
          $this->branch);
        break;
      case self::REFTYPE_BRANCH:
      default:
        $message = pht(
          'Switched to branch **%s**. Identifying and merging...',
          $this->branch);
        break;
    }

    echo phutil_console_format($message."\n");
  }

  private function printPendingCommits() {
    $repository_api = $this->getRepositoryAPI();

    if ($repository_api instanceof ArcanistGitAPI) {
      list($out) = $repository_api->execxLocal(
        'log --oneline %s %s --',
        $this->branch,
        '^'.$this->onto);
    } else if ($repository_api instanceof ArcanistMercurialAPI) {
      $common_ancestor = $repository_api->getCanonicalRevisionName(
        hgsprintf('ancestor(%s,%s)',
          $this->onto,
          $this->branch));

      $branch_range = hgsprintf(
        'reverse((%s::%s) - %s)',
        $common_ancestor,
        $this->branch,
        $common_ancestor);

      list($out) = $repository_api->execxLocal(
        'log -r %s --template %s',
        $branch_range,
        '{node|short} {desc|firstline}\n');
    }

    if (!trim($out)) {
      $this->restoreBranch();
      throw new ArcanistUsageException(
        pht('No commits to land from %s.', $this->branch));
    }

    echo pht("The following commit(s) will be landed:\n\n%s", $out), "\n";
  }

  private function findRevision() {
    $repository_api = $this->getRepositoryAPI();

    $this->parseBaseCommitArgument(array($this->ontoRemoteBranch));

    $revision_id = $this->getArgument('revision');
    if ($revision_id) {
      $revision_id = $this->normalizeRevisionID($revision_id);
      $revisions = $this->getConduit()->callMethodSynchronous(
        'differential.query',
        array(
          'ids' => array($revision_id),
        ));
      if (!$revisions) {
        throw new ArcanistUsageException(pht(
          "No such revision '%s'!",
          "D{$revision_id}"));
      }
    } else {
      $revisions = $repository_api->loadWorkingCopyDifferentialRevisions(
        $this->getConduit(),
        array());
    }

    if (!count($revisions)) {
      throw new ArcanistUsageException(pht(
        "arc can not identify which revision exists on %s '%s'. Update the ".
        "revision with recent changes to synchronize the %s name and hashes, ".
        "or use '%s' to amend the commit message at HEAD, or use ".
        "'%s' to select a revision explicitly.",
        $this->branchType,
        $this->branch,
        $this->branchType,
        'arc amend',
        '--revision <id>'));
    } else if (count($revisions) > 1) {
      switch ($this->branchType) {
        case self::REFTYPE_BOOKMARK:
          $message = pht(
            "There are multiple revisions on feature bookmark '%s' which are ".
            "not present on '%s':\n\n".
            "%s\n".
            'Separate these revisions onto different bookmarks, or use '.
            '--revision <id> to use the commit message from <id> '.
            'and land them all.',
            $this->branch,
            $this->onto,
            $this->renderRevisionList($revisions));
          break;
        case self::REFTYPE_BRANCH:
        default:
          $message = pht(
            "There are multiple revisions on feature branch '%s' which are ".
            "not present on '%s':\n\n".
            "%s\n".
            'Separate these revisions onto different branches, or use '.
            '--revision <id> to use the commit message from <id> '.
            'and land them all.',
            $this->branch,
            $this->onto,
            $this->renderRevisionList($revisions));
          break;
      }

      throw new ArcanistUsageException($message);
    }

    $this->revision = head($revisions);

    $rev_status = $this->revision['status'];
    $rev_id = $this->revision['id'];
    $rev_title = $this->revision['title'];
    $rev_auxiliary = idx($this->revision, 'auxiliary', array());

    $full_name = pht('D%d: %s', $rev_id, $rev_title);

    if ($this->revision['authorPHID'] != $this->getUserPHID()) {
      $other_author = $this->getConduit()->callMethodSynchronous(
        'user.query',
        array(
          'phids' => array($this->revision['authorPHID']),
        ));
      $other_author = ipull($other_author, 'userName', 'phid');
      $other_author = $other_author[$this->revision['authorPHID']];
      $ok = phutil_console_confirm(pht(
        "This %s has revision '%s' but you are not the author. Land this ".
        "revision by %s?",
        $this->branchType,
        $full_name,
        $other_author));
      if (!$ok) {
        throw new ArcanistUserAbortException();
      }
    }

    $state_warning = null;
    $state_header = null;
    if ($rev_status == ArcanistDifferentialRevisionStatus::CHANGES_PLANNED) {
      $state_header = pht('REVISION HAS CHANGES PLANNED');
      $state_warning = pht(
        'The revision you are landing ("%s") is currently in the "%s" state, '.
        'indicating that you expect to revise it before moving forward.'.
        "\n\n".
        'Normally, you should resubmit it for review and wait until it is '.
        '"%s" by reviewers before you continue.'.
        "\n\n".
        'To resubmit the revision for review, either: update the revision '.
        'with revised changes; or use "Request Review" from the web interface.',
        $full_name,
        pht('Changes Planned'),
        pht('Accepted'));
    } else if ($rev_status != ArcanistDifferentialRevisionStatus::ACCEPTED) {
      $state_header = pht('REVISION HAS NOT BEEN ACCEPTED');
      $state_warning = pht(
        'The revision you are landing ("%s") has not been "%s" by reviewers.',
        $full_name,
        pht('Accepted'));
    }

    if ($state_warning !== null) {
      $prompt = pht('Land revision in the wrong state?');

      id(new PhutilConsoleBlock())
        ->addParagraph(tsprintf('<bg:yellow>** %s **</bg>', $state_header))
        ->addParagraph(tsprintf('%B', $state_warning))
        ->draw();

      $ok = phutil_console_confirm($prompt);
      if (!$ok) {
        throw new ArcanistUserAbortException();
      }
    }

    if ($rev_auxiliary) {
      $phids = idx($rev_auxiliary, 'phabricator:depends-on', array());
      if ($phids) {
        $dep_on_revs = $this->getConduit()->callMethodSynchronous(
          'differential.query',
           array(
             'phids' => $phids,
             'status' => 'status-open',
           ));

        $open_dep_revs = array();
        foreach ($dep_on_revs as $dep_on_rev) {
          $dep_on_rev_id = $dep_on_rev['id'];
          $dep_on_rev_title = $dep_on_rev['title'];
          $dep_on_rev_status = $dep_on_rev['status'];
          $open_dep_revs[$dep_on_rev_id] = $dep_on_rev_title;
        }

        if (!empty($open_dep_revs)) {
          $open_revs = array();
          foreach ($open_dep_revs as $id => $title) {
            $open_revs[] = '    - D'.$id.': '.$title;
          }
          $open_revs = implode("\n", $open_revs);

          echo pht(
            "Revision '%s' depends on open revisions:\n\n%s",
            "D{$rev_id}: {$rev_title}",
            $open_revs);

          $ok = phutil_console_confirm(pht('Continue anyway?'));
          if (!$ok) {
            throw new ArcanistUserAbortException();
          }
        }
      }
    }

    $message = $this->getConduit()->callMethodSynchronous(
      'differential.getcommitmessage',
      array(
        'revision_id' => $rev_id,
      ));

    $this->messageFile = new TempFile();
    Filesystem::writeFile($this->messageFile, $message);

    echo pht(
      "Landing revision '%s'...",
      "D{$rev_id}: {$rev_title}")."\n";

    $diff_phid = idx($this->revision, 'activeDiffPHID');
    if ($diff_phid) {
      $this->checkForBuildables($diff_phid);
    }
  }

  private function pullFromRemote() {
    $repository_api = $this->getRepositoryAPI();

    $local_ahead_of_remote = false;
    if ($this->isGit) {
      $repository_api->execxLocal('checkout %s', $this->onto);

      echo phutil_console_format(pht(
        "Switched to branch **%s**. Updating branch...\n",
        $this->onto));

      try {
        $repository_api->execxLocal('pull --ff-only --no-stat');
      } catch (CommandException $ex) {
        if (!$this->isGitSvn) {
          throw $ex;
        }
      }
      list($out) = $repository_api->execxLocal(
        'log %s..%s',
        $this->ontoRemoteBranch,
        $this->onto);
      if (strlen(trim($out))) {
        $local_ahead_of_remote = true;
      } else if ($this->isGitSvn) {
        $repository_api->execxLocal('svn rebase');
      }

    } else if ($this->isHg) {
      echo phutil_console_format(pht('Updating **%s**...', $this->onto)."\n");

      try {
        list($out, $err) = $repository_api->execxLocal('pull');

        $divergedbookmark = $this->onto.'@'.$repository_api->getBranchName();
        if (strpos($err, $divergedbookmark) !== false) {
          throw new ArcanistUsageException(phutil_console_format(pht(
            "Local bookmark **%s** has diverged from the server's **%s** ".
            "(now labeled **%s**). Please resolve this divergence and run ".
            "'%s' again.",
            $this->onto,
            $this->onto,
            $divergedbookmark,
            'arc land')));
        }
      } catch (CommandException $ex) {
        $err = $ex->getError();
        $stdout = $ex->getStdout();

        // Copied from: PhabricatorRepositoryPullLocalDaemon.php
        // NOTE: Between versions 2.1 and 2.1.1, Mercurial changed the
        // behavior of "hg pull" to return 1 in case of a successful pull
        // with no changes. This behavior has been reverted, but users who
        // updated between Feb 1, 2012 and Mar 1, 2012 will have the
        // erroring version. Do a dumb test against stdout to check for this
        // possibility.
        // See: https://github.com/phacility/phabricator/issues/101/

        // NOTE: Mercurial has translated versions, which translate this error
        // string. In a translated version, the string will be something else,
        // like "aucun changement trouve". There didn't seem to be an easy way
        // to handle this (there are hard ways but this is not a common
        // problem and only creates log spam, not application failures).
        // Assume English.

        // TODO: Remove this once we're far enough in the future that
        // deployment of 2.1 is exceedingly rare?
        if ($err != 1 || !preg_match('/no changes found/', $stdout)) {
          throw $ex;
        }
      }

      // Pull succeeded. Now make sure master is not on an outgoing change
      if ($repository_api->supportsPhases()) {
        list($out) = $repository_api->execxLocal(
          'log -r %s --template %s', $this->onto, '{phase}');
        if ($out != 'public') {
          $local_ahead_of_remote = true;
        }
      } else {
        // execManual instead of execx because outgoing returns
        // code 1 when there is nothing outgoing
        list($err, $out) = $repository_api->execManualLocal(
          'outgoing -r %s',
          $this->onto);

        // $err === 0 means something is outgoing
        if ($err === 0) {
          $local_ahead_of_remote = true;
        }
      }
    }

    if ($local_ahead_of_remote) {
      throw new ArcanistUsageException(pht(
        "Local %s '%s' is ahead of remote %s '%s', so landing a feature ".
        "%s would push additional changes. Push or reset the changes in '%s' ".
        "before running '%s'.",
        $this->ontoType,
        $this->onto,
        $this->ontoType,
        $this->ontoRemoteBranch,
        $this->ontoType,
        $this->onto,
        'arc land'));
    }
  }

  private function rebase() {
    $repository_api = $this->getRepositoryAPI();

    chdir($repository_api->getPath());
    if ($this->isHg) {
      $onto_tip = $repository_api->getCanonicalRevisionName($this->onto);
      $common_ancestor = $repository_api->getCanonicalRevisionName(
        hgsprintf('ancestor(%s, %s)', $this->onto, $this->branch));

      // Only rebase if the local branch is not at the tip of the onto branch.
      if ($onto_tip != $common_ancestor) {
        // keep branch here so later we can decide whether to remove it
        $err = $repository_api->execPassthru(
          'rebase -d %s --keepbranches',
          $this->onto);
        if ($err) {
          echo phutil_console_format("%s\n", pht('Aborting rebase'));
          $repository_api->execManualLocal('rebase --abort');
          $this->restoreBranch();
          throw new ArcanistUsageException(pht(
            "'%s' failed and the rebase was aborted. This is most ".
            "likely due to conflicts. Manually rebase %s onto %s, resolve ".
            "the conflicts, then run '%s' again.",
            sprintf('hg rebase %s', $this->onto),
            $this->branch,
            $this->onto,
            'arc land'));
        }
      }
    }

    $repository_api->reloadWorkingCopy();
  }

  private function squash() {
    $repository_api = $this->getRepositoryAPI();

    if ($this->isGit) {
      $repository_api->execxLocal('checkout %s', $this->onto);
      $repository_api->execxLocal(
        'merge --no-stat --squash --ff-only %s',
        $this->branch);
    } else if ($this->isHg) {
      // The hg code is a little more complex than git's because we
      // need to handle the case where the landing branch has child branches:
      // -a--------b  master
      //   \
      //    w--x  mybranch
      //        \--y  subbranch1
      //         \--z  subbranch2
      //
      // arc land --branch mybranch --onto master :
      // -a--b--wx  master
      //          \--y  subbranch1
      //           \--z  subbranch2

      $branch_rev_id = $repository_api->getCanonicalRevisionName($this->branch);

      // At this point $this->onto has been pulled from remote and
      // $this->branch has been rebased on top of onto(by the rebase()
      // function). So we're guaranteed to have onto as an ancestor of branch
      // when we use first((onto::branch)-onto) below.
      $branch_root = $repository_api->getCanonicalRevisionName(
        hgsprintf('first((%s::%s)-%s)',
          $this->onto,
          $this->branch,
          $this->onto));

      $branch_range = hgsprintf(
        '(%s::%s)',
        $branch_root,
        $this->branch);

      if (!$this->keepBranch) {
        $this->handleAlternateBranches($branch_root, $branch_range);
      }

      // Collapse just the landing branch onto master.
      // Leave its children on the original branch.
      $err = $repository_api->execPassthru(
        'rebase --collapse --keep --logfile %s -r %s -d %s',
        $this->messageFile,
        $branch_range,
        $this->onto);

      if ($err) {
        $repository_api->execManualLocal('rebase --abort');
        $this->restoreBranch();
        throw new ArcanistUsageException(
          pht(
            "Squashing the commits under %s failed. ".
            "Manually squash your commits and run '%s' again.",
            $this->branch,
            'arc land'));
      }

      if ($repository_api->isBookmark($this->branch)) {
        // a bug in mercurial means bookmarks end up on the revision prior
        // to the collapse when using --collapse with --keep,
        // so we manually move them to the correct spots
        // see: http://bz.selenic.com/show_bug.cgi?id=3716
        $repository_api->execxLocal(
          'bookmark -f %s',
          $this->onto);

        $repository_api->execxLocal(
          'bookmark -f %s -r %s',
          $this->branch,
          $branch_rev_id);
      }

      // check if the branch had children
      list($output) = $repository_api->execxLocal(
        'log -r %s --template %s',
        hgsprintf('children(%s)', $this->branch),
        '{node}\n');

      $child_branch_roots = phutil_split_lines($output, false);
      $child_branch_roots = array_filter($child_branch_roots);
      if ($child_branch_roots) {
        // move the branch's children onto the collapsed commit
        foreach ($child_branch_roots as $child_root) {
          $repository_api->execxLocal(
            'rebase -d %s -s %s --keep --keepbranches',
            $this->onto,
            $child_root);
        }
      }

      // All the rebases may have moved us to another branch
      // so we move back.
      $repository_api->execxLocal('checkout %s', $this->onto);
    }
  }

  /**
   * Detect alternate branches and prompt the user for how to handle
   * them. An alternate branch is a branch that forks from the landing
   * branch prior to the landing branch tip.
   *
   * In a situation like this:
   *   -a--------b  master
   *     \
   *      w--x  landingbranch
   *       \  \-- g subbranch
   *        \--y  altbranch1
   *         \--z  altbranch2
   *
   * y and z are alternate branches and will get deleted by the squash,
   * so we need to detect them and ask the user what they want to do.
   *
   * @param string The revision id of the landing branch's root commit.
   * @param string The revset specifying all the commits in the landing branch.
   * @return void
   */
  private function handleAlternateBranches($branch_root, $branch_range) {
    $repository_api = $this->getRepositoryAPI();

    // Using the tree in the doccomment, the revset below resolves as follows:
    // 1. roots(descendants(w) - descendants(x) - (w::x))
    // 2. roots({x,g,y,z} - {g} - {w,x})
    // 3. roots({y,z})
    // 4. {y,z}
    $alt_branch_revset = hgsprintf(
      'roots(descendants(%s)-descendants(%s)-%R)',
      $branch_root,
      $this->branch,
      $branch_range);
    list($alt_branches) = $repository_api->execxLocal(
      'log --template %s -r %s',
      '{node}\n',
       $alt_branch_revset);

    $alt_branches = phutil_split_lines($alt_branches, false);
    $alt_branches = array_filter($alt_branches);

    $alt_count = count($alt_branches);
    if ($alt_count > 0) {
      $input = phutil_console_prompt(pht(
        "%s '%s' has %s %s(s) forking off of it that would be deleted ".
        "during a squash. Would you like to keep a non-squashed copy, rebase ".
        "them on top of '%s', or abort and deal with them yourself? ".
        "(k)eep, (r)ebase, (a)bort:",
        ucfirst($this->branchType),
        $this->branch,
        $alt_count,
        $this->branchType,
        $this->branch));

      if ($input == 'k' || $input == 'keep') {
        $this->keepBranch = true;
      } else if ($input == 'r' || $input == 'rebase') {
        foreach ($alt_branches as $alt_branch) {
          $repository_api->execxLocal(
            'rebase --keep --keepbranches -d %s -s %s',
            $this->branch,
            $alt_branch);
        }
      } else if ($input == 'a' || $input == 'abort') {
        $branch_string = implode("\n", $alt_branches);
        echo
          "\n",
          pht(
            "Remove the %s starting at these revisions and run %s again:\n%s",
            $this->branchType.'s',
            $branch_string,
            'arc land'),
          "\n\n";
        throw new ArcanistUserAbortException();
      } else {
        throw new ArcanistUsageException(
          pht('Invalid choice. Aborting arc land.'));
      }
    }
  }

  private function merge() {
    $repository_api = $this->getRepositoryAPI();

    // In immutable histories, do a --no-ff merge to force a merge commit with
    // the right message.
    $repository_api->execxLocal('checkout %s', $this->onto);

    chdir($repository_api->getPath());
    if ($this->isGit) {
      $err = phutil_passthru(
        'git merge --no-stat --no-ff --no-commit %s',
        $this->branch);

      if ($err) {
        throw new ArcanistUsageException(pht(
          "'%s' failed. Your working copy has been left in a partially ".
          "merged state. You can: abort with '%s'; or follow the ".
          "instructions to complete the merge.",
          'git merge',
          'git merge --abort'));
      }
    } else if ($this->isHg) {
      // HG arc land currently doesn't support --merge.
      // When merging a bookmark branch to a master branch that
      // hasn't changed since the fork, mercurial fails to merge.
      // Instead of only working in some cases, we just disable --merge
      // until there is a demand for it.
      // The user should never reach this line, since --merge is
      // forbidden at the command line argument level.
      throw new ArcanistUsageException(
        pht('%s is not currently supported for hg repos.', '--merge'));
    }
  }

  private function push() {
    $repository_api = $this->getRepositoryAPI();

    // These commands can fail legitimately (e.g. commit hooks)
    try {
      if ($this->isGit) {
        $repository_api->execxLocal('commit -F %s', $this->messageFile);
        if (phutil_is_windows()) {
          // Occasionally on large repositories on Windows, Git can exit with
          // an unclean working copy here. This prevents reverts from being
          // pushed to the remote when this occurs.
          $this->requireCleanWorkingCopy();
        }
      } else if ($this->isHg) {
        // hg rebase produces a commit earlier as part of rebase
        if (!$this->useSquash) {
          $repository_api->execxLocal(
            'commit --logfile %s',
            $this->messageFile);
        }
      }
      // We dispatch this event so we can run checks on the merged revision,
      // right before it gets pushed out. It's easier to do this in arc land
      // than to try to hook into git/hg.
      $this->didCommitMerge();
    } catch (Exception $ex) {
      $this->executeCleanupAfterFailedPush();
      throw $ex;
    }

    if ($this->getArgument('hold')) {
      echo phutil_console_format(pht(
        'Holding change in **%s**: it has NOT been pushed yet.',
        $this->onto)."\n");
    } else {
      echo pht('Pushing change...'), "\n\n";

      chdir($repository_api->getPath());

      if ($this->isGitSvn) {
        $err = phutil_passthru('git svn dcommit');
        $cmd = 'git svn dcommit';
      } else if ($this->isGit) {
        $err = phutil_passthru('git push %s %s', $this->remote, $this->onto);
        $cmd = 'git push';
      } else if ($this->isHgSvn) {
        // hg-svn doesn't support 'push -r', so we do a normal push
        // which hg-svn modifies to only push the current branch and
        // ancestors.
        $err = $repository_api->execPassthru('push %s', $this->remote);
        $cmd = 'hg push';
      } else if ($this->isHg) {
        if (strlen($this->remote)) {
          $err = $repository_api->execPassthru(
            'push -r %s %s',
            $this->onto,
            $this->remote);
        } else {
          $err = $repository_api->execPassthru(
            'push -r %s',
            $this->onto);
        }
        $cmd = 'hg push';
      }

      if ($err) {
        echo phutil_console_format(
          "<bg:red>**   %s   **</bg>\n",
          pht('PUSH FAILED!'));
        $this->executeCleanupAfterFailedPush();
        if ($this->isGit) {
          throw new ArcanistUsageException(pht(
            "'%s' failed! Fix the error and run '%s' again.",
            $cmd,
            'arc land'));
        }
        throw new ArcanistUsageException(pht(
          "'%s' failed! Fix the error and push this change manually.",
          $cmd));
      }

      $this->didPush();

      echo "\n";
    }
  }

  private function executeCleanupAfterFailedPush() {
    $repository_api = $this->getRepositoryAPI();
    if ($this->isGit) {
      $repository_api->execxLocal('reset --hard HEAD^');
      $this->restoreBranch();
    } else if ($this->isHg) {
      $repository_api->execxLocal(
        '--config extensions.mq= strip %s',
        $this->onto);
      $this->restoreBranch();
    }
  }

  private function cleanupBranch() {
    $repository_api = $this->getRepositoryAPI();

    echo pht('Cleaning up feature %s...', $this->branchType), "\n";
    if ($this->isGit) {
      list($ref) = $repository_api->execxLocal(
        'rev-parse --verify %s',
        $this->branch);
      $ref = trim($ref);
      $recovery_command = csprintf(
        'git checkout -b %s %s',
        $this->branch,
        $ref);
      echo pht('(Use `%s` if you want it back.)', $recovery_command), "\n";
      $repository_api->execxLocal('branch -D %s', $this->branch);
    } else if ($this->isHg) {
      $common_ancestor = $repository_api->getCanonicalRevisionName(
        hgsprintf('ancestor(%s,%s)', $this->onto, $this->branch));

      $branch_root = $repository_api->getCanonicalRevisionName(
        hgsprintf('first((%s::%s)-%s)',
          $common_ancestor,
          $this->branch,
          $common_ancestor));

      $repository_api->execxLocal(
        '--config extensions.mq= strip -r %s',
        $branch_root);

      if ($repository_api->isBookmark($this->branch)) {
        $repository_api->execxLocal('bookmark -d %s', $this->branch);
      }
    }

    if ($this->getArgument('delete-remote')) {
      if ($this->isHg) {
        // named branches were closed as part of the earlier commit
        // so only worry about bookmarks
        if ($repository_api->isBookmark($this->branch)) {
          $repository_api->execxLocal(
            'push -B %s %s',
            $this->branch,
            $this->remote);
        }
      }
    }
  }

  public function getSupportedRevisionControlSystems() {
    return array('git', 'hg');
  }

  private function getBranchOrBookmark() {
    $repository_api = $this->getRepositoryAPI();
    if ($this->isGit) {
      $branch = $repository_api->getBranchName();

      // If we don't have a branch name, just use whatever's at HEAD.
      if (!strlen($branch) && !$this->isGitSvn) {
        $branch = $repository_api->getWorkingCopyRevision();
      }
    } else if ($this->isHg) {
      $branch = $repository_api->getActiveBookmark();
      if (!$branch) {
        $branch = $repository_api->getBranchName();
      }
    }

    return $branch;
  }

  private function getBranchType($branch) {
    $repository_api = $this->getRepositoryAPI();
    if ($this->isHg && $repository_api->isBookmark($branch)) {
      return 'bookmark';
    }
    return 'branch';
  }

  /**
   * Restore the original branch, e.g. after a successful land or a failed
   * pull.
   */
  private function restoreBranch() {
    $repository_api = $this->getRepositoryAPI();
    $repository_api->execxLocal('checkout %s', $this->oldBranch);
    if ($this->isGit) {
      $repository_api->execxLocal('submodule update --init --recursive');
    }
    echo pht(
      "Switched back to %s %s.\n",
      $this->branchType,
      phutil_console_format('**%s**', $this->oldBranch));
  }


  /**
   * Check if a diff has a running or failed buildable, and prompt the user
   * before landing if it does.
   */
  private function checkForBuildables($diff_phid) {
    // Try to use the more modern check which respects the "Warn on Land"
    // behavioral flag on build plans if we can. This newer check won't work
    // unless the server is running code from March 2019 or newer since the
    // API methods we need won't exist yet. We'll fall back to the older check
    // if this one doesn't work out.
    try {
      $this->checkForBuildablesWithPlanBehaviors($diff_phid);
      return;
    } catch (ArcanistUserAbortException $abort_ex) {
      throw $abort_ex;
    } catch (Exception $ex) {
      // Continue with the older approach, below.
    }

    // NOTE: Since Harbormaster is still beta and this stuff all got added
    // recently, just bail if we can't find a buildable. This is just an
    // advisory check intended to prevent human error.

    try {
      $buildables = $this->getConduit()->callMethodSynchronous(
        'harbormaster.querybuildables',
        array(
          'buildablePHIDs' => array($diff_phid),
          'manualBuildables' => false,
        ));
    } catch (ConduitClientException $ex) {
      return;
    }

    if (!$buildables['data']) {
      // If there's no corresponding buildable, we're done.
      return;
    }

    $console = PhutilConsole::getConsole();

    $buildable = head($buildables['data']);

    if ($buildable['buildableStatus'] == 'passed') {
      $console->writeOut(
        "**<bg:green> %s </bg>** %s\n",
        pht('BUILDS PASSED'),
        pht('Harbormaster builds for the active diff completed successfully.'));
      return;
    }

    switch ($buildable['buildableStatus']) {
      case 'building':
        $message = pht(
          'Harbormaster is still building the active diff for this revision.');
        $prompt = pht('Land revision anyway, despite ongoing build?');
        break;
      case 'failed':
        $message = pht(
          'Harbormaster failed to build the active diff for this revision.');
        $prompt = pht('Land revision anyway, despite build failures?');
        break;
      default:
        // If we don't recognize the status, just bail.
        return;
    }

    $builds = $this->queryBuilds(
      array(
        'buildablePHIDs' => array($buildable['phid']),
      ));

    $console->writeOut($message."\n\n");

    $builds = msortv($builds, 'getStatusSortVector');
    foreach ($builds as $build) {
      $ansi_color = $build->getStatusANSIColor();
      $status_name = $build->getStatusName();
      $object_name = $build->getObjectName();
      $build_name = $build->getName();

      echo tsprintf(
        "    **<bg:".$ansi_color."> %s </bg>** %s: %s\n",
        $status_name,
        $object_name,
        $build_name);
    }

    $console->writeOut(
      "\n%s\n\n    **%s**: __%s__",
      pht('You can review build details here:'),
      pht('Harbormaster URI'),
      $buildable['uri']);

    if (!phutil_console_confirm($prompt)) {
      throw new ArcanistUserAbortException();
    }
  }

  private function checkForBuildablesWithPlanBehaviors($diff_phid) {
    // TODO: These queries should page through all results instead of fetching
    // only the first page, but we don't have good primitives to support that
    // in "master" yet.

    $this->writeInfo(
      pht('BUILDS'),
      pht('Checking build status...'));

    $raw_buildables = $this->getConduit()->callMethodSynchronous(
      'harbormaster.buildable.search',
      array(
        'constraints' => array(
          'objectPHIDs' => array(
            $diff_phid,
          ),
          'manual' => false,
        ),
      ));

    if (!$raw_buildables['data']) {
      return;
    }

    $buildables = $raw_buildables['data'];
    $buildable_phids = ipull($buildables, 'phid');

    $raw_builds = $this->getConduit()->callMethodSynchronous(
      'harbormaster.build.search',
      array(
        'constraints' => array(
          'buildables' => $buildable_phids,
        ),
      ));

    if (!$raw_builds['data']) {
      return;
    }

    $builds = array();
    foreach ($raw_builds['data'] as $raw_build) {
      $build_ref = ArcanistBuildRef::newFromConduit($raw_build);
      $build_phid = $build_ref->getPHID();
      $builds[$build_phid] = $build_ref;
    }

    $plan_phids = mpull($builds, 'getBuildPlanPHID');
    $plan_phids = array_values($plan_phids);

    $raw_plans = $this->getConduit()->callMethodSynchronous(
      'harbormaster.buildplan.search',
      array(
        'constraints' => array(
          'phids' => $plan_phids,
        ),
      ));

    $plans = array();
    foreach ($raw_plans['data'] as $raw_plan) {
      $plan_ref = ArcanistBuildPlanRef::newFromConduit($raw_plan);
      $plan_phid = $plan_ref->getPHID();
      $plans[$plan_phid] = $plan_ref;
    }

    $ongoing_builds = array();
    $failed_builds = array();

    $builds = msortv($builds, 'getStatusSortVector');
    foreach ($builds as $build_ref) {
      $plan = idx($plans, $build_ref->getBuildPlanPHID());
      if (!$plan) {
        continue;
      }

      $plan_behavior = $plan->getBehavior('arc-land', 'always');
      $if_building = ($plan_behavior == 'building');
      $if_complete = ($plan_behavior == 'complete');
      $if_never = ($plan_behavior == 'never');

      // If the build plan "Never" warns when landing, skip it.
      if ($if_never) {
        continue;
      }

      // If the build plan warns when landing "If Complete" but the build is
      // not complete, skip it.
      if ($if_complete && !$build_ref->isComplete()) {
        continue;
      }

      // If the build plan warns when landing "If Building" but the build is
      // complete, skip it.
      if ($if_building && $build_ref->isComplete()) {
        continue;
      }

      // Ignore passing builds.
      if ($build_ref->isPassed()) {
        continue;
      }

      if (!$build_ref->isComplete()) {
        $ongoing_builds[] = $build_ref;
      } else {
        $failed_builds[] = $build_ref;
      }
    }

    if (!$ongoing_builds && !$failed_builds) {
      return;
    }

    if ($failed_builds) {
      $this->writeWarn(
        pht('BUILD FAILURES'),
        pht(
          'Harbormaster failed to build the active diff for this revision:'));
      $prompt = pht('Land revision anyway, despite build failures?');
    } else if ($ongoing_builds) {
      $this->writeWarn(
        pht('ONGOING BUILDS'),
        pht(
          'Harbormaster is still building the active diff for this revision:'));
      $prompt = pht('Land revision anyway, despite ongoing build?');
    }

    $show_builds = array_merge($failed_builds, $ongoing_builds);
    echo "\n";
    foreach ($show_builds as $build_ref) {
      $ansi_color = $build_ref->getStatusANSIColor();
      $status_name = $build_ref->getStatusName();
      $object_name = $build_ref->getObjectName();
      $build_name = $build_ref->getName();

      echo tsprintf(
        "    **<bg:".$ansi_color."> %s </bg>** %s: %s\n",
        $status_name,
        $object_name,
        $build_name);
    }

    echo tsprintf(
      "\n%s\n\n",
      pht('You can review build details here:'));

    foreach ($buildables as $buildable) {
      $buildable_uri = id(new PhutilURI($this->getConduitURI()))
        ->setPath(sprintf('/B%d', $buildable['id']));

      echo tsprintf(
        "          **%s**: __%s__\n",
        pht('Buildable %d', $buildable['id']),
        $buildable_uri);
    }

    if (!phutil_console_confirm($prompt)) {
      throw new ArcanistUserAbortException();
    }
  }

  public function buildEngineMessage(ArcanistLandEngine $engine) {
    // TODO: This is oh-so-gross.
    $this->findRevision();
    $engine->setCommitMessageFile($this->messageFile);
  }

  public function didCommitMerge() {
    $this->dispatchEvent(
      ArcanistEventType::TYPE_LAND_WILLPUSHREVISION,
      array());
  }

  public function didPush() {
    $this->askForRepositoryUpdate();

    $mark_workflow = $this->buildChildWorkflow(
      'close-revision',
      array(
        '--finalize',
        '--quiet',
        $this->revision['id'],
      ));
    $mark_workflow->run();
  }

  private function queryBuilds(array $constraints) {
    $conduit = $this->getConduit();

    // NOTE: This method only loads the 100 most recent builds. It's rare for
    // a revision to have more builds than that and there's currently no paging
    // wrapper for "*.search" Conduit API calls available in Arcanist.

    try {
      $raw_result = $conduit->callMethodSynchronous(
        'harbormaster.build.search',
        array(
          'constraints' => $constraints,
        ));
    } catch (Exception $ex) {
      // If the server doesn't have "harbormaster.build.search" yet (Aug 2016),
      // try the older "harbormaster.querybuilds" instead.
      $raw_result = $conduit->callMethodSynchronous(
        'harbormaster.querybuilds',
        $constraints);
    }

    $refs = array();
    foreach ($raw_result['data'] as $raw_data) {
      $refs[] = ArcanistBuildRef::newFromConduit($raw_data);
    }

    return $refs;
  }


}
