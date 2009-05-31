#!/usr/bin/env php
<?php
// $Id$

/**
 * @file xsvn-pre-commit.php
 *
 * Provides access checking for 'svn commit' commands.
 *
 * Copyright 2009 by Daniel Hackney ("chrono325", http://drupal.org/user/384635)
 */

function xsvn_help($cli, $output_stream) {
  fwrite($output_stream, "Usage: $cli <config file> REPO_PATH TX_NAME\n\n");
}

/**
 * Returns the author of the given transaction in the repository.
 *
 * @param tx
 *   The transaction ID for which to find the author.
 *
 * @param repo
 *   The repository in which to look for the author.
 */
function xsvn_get_commit_author($tx, $repo) {
  return shell_exec("svnlook author -t $tx $repo");
}

/**
 * Returns the files and directories which were modified by the transaction with
 * their status.
 *
 * @param tx
 *   The transaction ID for which to find the modified files.
 *
 * @param repo
 *   The repository.
 *
 * @return
 *   An array of files and directories modified by the transaction. The keys are
 *   the paths of the file and the value is the status of the item, as returned
 *   by "svnlook changed".
 */
function xsvn_get_commit_files($tx, $repo) {
  $str = shell_exec("svnlook changed -t $tx $repo");
  $lines = explode($str, "\n", -1); // SVN returns a newline at the end.

  // Separate the status from the path names.
  foreach ($lines as $line) {
    list($status, $path) = preg_split("/\s+/", $line);
    $items[$path] = $status;
  }
  return $items;
}


function xsvn_get_operation_item($filename, $dir, $xsvn['cwd']) {

    $item = array(
    'type' => VERSIONCONTROL_ITEM_FILE,
    'path' => $repository_path,
    'source_items' => array(),
  );
}

/**
 * The main function of the hook.
 *
 * Expects the following arguments:
 *
 *   - $argv[1] - The path of the configuration file, xsvn-config.php.
 *   - $argv[2] - The path of the subversion repository.
 *   - $argv[3] - Commit transaction name.
 *
 * @param argc
 *   The number of arguments on the command line.
 *
 * @param argv
 *   Array of the arguments.
 */
function xsvn_init($argc, $argv) {
  $this_file = array_shift($argv);   // argv[0]

  if ($argc < 4) {
    xsvn_help($this_file, STDERR);
    exit(3);
  }

  $config_file = array_shift($argv); // argv[1]
  $repo        = array_shift($argv); // argv[2]
  $tx          = array_shift($argv); // argv[3]
  $username    = xsvn_get_commit_author($tx, $repo);
  $filenames   = xsvn_get_commit_files($tx, $repo);

  // Load the configuration file and bootstrap Drupal.
  if (!file_exists($config_file)) {
    fwrite(STDERR, "Error: failed to load configuration file.\n");
    exit(4);
  }
  include_once $config_file;

  // Check temporary file storage.
  $tempdir = xsvn_get_temp_directory($xsvn['temp']);

    // Admins and other privileged users don't need to go through any checks.
  if (!in_array($username, $xsvn['allowed_users'])) {
    // Do a full Drupal bootstrap.
    xsvn_bootstrap($xsvn);

    // Construct a minimal commit operation array.
    $operation = array(
      'type' => VERSIONCONTROL_OPERATION_COMMIT,
      'repo_id' => $xsvn['repo_id'],
      'username' => $username,
      'labels' => array(), // TODO: don't support labels yet.
    );

    $operation_items = array();
    foreach ($filenames as $filename) {
      list($path, $item) = xsvn_get_operation_item($filename, $dir, $xsvn['cwd']);
      $operation_items[$path] = $item;
    }
    $access = versioncontrol_has_write_access($operation, $operation_items);

    // Fail and print out error messages if commit access has been denied.
    if (!$access) {
      fwrite(STDERR, implode("\n\n", versioncontrol_get_access_errors()) ."\n\n");
      exit(6);
    }
  }
}
