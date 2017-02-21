#! /usr/bin/env php
<?php

echo "Pulverize - A multi-process rendering script for Blender VSE.\n";
echo "Version 1.0\n";
echo "Copyright 2017 Hunter Perrin\n";
echo "Licensed under GPL, just like Blender.\n";

if (!isset($argv[1])) {
 die("\n\nUsage: pulverize.php <blender_project_file> [<number_of_processes>]\n\n");
}

$lineWidth = (int) exec('tput cols');

$blenderFile = $argv[1];
$toolScript = __DIR__.'/pulverize_tool.py';
if (!file_exists($blenderFile)) {
  die("You didn't give me a valid Blender project file.\n");
}
if (!file_exists($toolScript)) {
  die("My tool script 'pulverize_tool.py' is missing.\n");
}

$shellBlenderFile = escapeshellarg($blenderFile);
$shellToolScript = escapeshellarg($toolScript);
$projectInfo = shell_exec("blender -b $shellBlenderFile -P $shellToolScript 2>/dev/null");

preg_match('/^FRAMES: (\d+) (\d+)$/m', $projectInfo, $matches);
list($startFrame, $endFrame) = array_slice($matches, 1, 2);
preg_match('/^OUTPUTDIR: (.+)$/m', $projectInfo, $matches);
$outputDir = $matches[1];
$shellOutputDir = escapeshellarg($outputDir);

if (!is_dir($outputDir)) {
  die("This script only works if your project's output is set to a directory. Please set it to a directory and try again.\n");
}

$frameLength = $endFrame - $startFrame + 1;
// Use all but one of the processors, so the system is still responsive.
$processors = (int) shell_exec("cat /proc/cpuinfo | egrep \"^processor\" | wc -l");
$processCountArg = (int) $argv[2];
$processCount = $processors - 1;
if ($processCountArg && $processCountArg <= $processors) {
  $processCount = $processCountArg;
}
$processFrameCount = floor($frameLength / $processCount);
$remainderFrames = $frameLength % $processCount;

echo <<<EOF

It looks like your machine has $processors processor(s).

Read from Blender file --
startFrame: $startFrame
endFrame: $endFrame
outputDir: $outputDir

Calculated these values for render --
frameLength: $frameLength
processCount: $processCount
processFrameCount: $processFrameCount
remainderFrames: $remainderFrames

Each process will render $processFrameCount frames, except the last will render an extra $remainderFrames frame(s).

EOF;





echo_header("Step 1/2 Rendering with Blender");

$descriptorspec = array(
  0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
  1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
  2 => array("pipe", "w") // stderr is a pipe that the child will write to
);
$processes = [];
$pipes = [];
$startTime = time();
// Start all the blender forks.
for ($i = 0; $i < $processCount; $i++) {
  $s = $startFrame + ($processFrameCount * $i);
  $e = $s + $processFrameCount - 1;

  if ($i == $processCount - 1) {
    // In the last process, add the remainder frames.
    $e += $remainderFrames;
  }

  $handle = proc_open("blender -b $shellBlenderFile -s $s -e $e -o {$shellOutputDir}blender_render_frames_####### -a", $descriptorspec, $pipes[$i]);
  $processes[$i] = [
    'handle' => $handle,
    's' => $s,
    'e' => $e,
    'frame' => $s
  ];
  stream_set_blocking($pipes[$i][0], false);
  stream_set_blocking($pipes[$i][1], false);
  stream_set_blocking($pipes[$i][2], false);
}

// Monitor them and print a progress bar.
echo "\n\n";
do {
  usleep(250000);
  $done = true;
  foreach ($processes as $curI => &$curProcess) {
    $status = proc_get_status($curProcess['handle']);
    if ($status['running']) {
      $done = false;
    } elseif (!isset($curProcess['time'])) {
      $curProcess['time'] = time() - $startTime;
    }
    $stderr = stream_get_contents($pipes[$curI][2]);
    if ($stderr) {
      //echo "\n\n--------------- StdErr Processs: $curI\n";
      //echo $stderr;
    }
    $stdout = stream_get_contents($pipes[$curI][1]);
    if ($stdout) {
      preg_match('/^Append frame (\d+)$/m', $stdout, $matches);
      if ($matches) {
        $curProcess['frame'] = (int) $matches[1];
      }
    }
  }

  $completedFrames = 0;
  foreach ($processes as $curProcess) {
    $completedFrames += $curProcess['frame'] - $curProcess['s'];
  }

  // Show a progress indicator.
  echo_progress_bar($completedFrames);
} while (!$done);
echo_progress_bar($frameLength);





echo_header("Step 2/2 Concatinating videos with FFMPEG");

$fileLsOutput = shell_exec("cd $shellOutputDir && ls -1 blender_render_frames_*");
$files = explode("\n", trim($fileLsOutput));
if (!$files) {
  die("Something went wrong, and I can't find the video files. Check to see if the render worked.");
}
$fileList = escapeshellarg(implode("|", $files));
$ext = explode(".", $files[0], 2)[1];
$startFramePadded = str_pad($startFrame, 7, '0', STR_PAD_LEFT);
$endFramePadded = str_pad($endFrame, 7, '0', STR_PAD_LEFT);
echo "$ ffmpeg -v error -stats -i concat:$fileList -c copy $startFramePadded-$endFramePadded.$ext\n";
passthru("cd $shellOutputDir && ffmpeg -v error -stats -i concat:$fileList -c copy $startFramePadded-$endFramePadded.$ext");

echo "\nRemoving temporary video files...\n";
foreach ($files as $curFile) {
  unlink("$outputDir/$curFile");
}






echo_header("All done!");

$totalSeconds = time() - $startTime;
$from = new DateTime;
$to = clone $from;
$to = $to->add(new DateInterval("PT{$totalSeconds}S"));
$diff = $from->diff($to);
$totalTime = $diff->format('%h:%I:%S');

$blenderSeconds = 0;
foreach ($processes as $curProcess) {
  $blenderSeconds += $curProcess['time'];
}
$from = new DateTime;
$to = clone $from;
$to = $to->add(new DateInterval("PT{$blenderSeconds}S"));
$diff = $from->diff($to);
$blenderTime = $diff->format('%h:%I:%S');

$savedSeconds = $blenderSeconds - $totalSeconds;
$from = new DateTime;
$to = clone $from;
$to = $to->add(new DateInterval("PT{$savedSeconds}S"));
$diff = $from->diff($to);
$savedTime = $diff->format('%h:%I:%S');

echo <<<EOF
Total time: $totalTime
Blender time: $blenderTime

You saved this much time by running this script instead of rendering directly from Blender's VSE:
$savedTime

EOF;





function echo_header($text) {
  global $lineWidth;

  echo "\n\n\n";
  echo str_repeat("#", $lineWidth)."\n";
  echo "# $text".str_repeat(" ", $lineWidth - strlen("# $text") - 1)."#\n";
  echo str_repeat("#", $lineWidth)."\n";
  echo "\n\n";
}

function echo_progress_bar($completedFrames) {
  global $lineWidth, $frameLength, $startTime;

  echo "\r";
  echo "\033[2K";
  echo "\x1b[A";
  echo "\033[2K";
  echo "\x1b[A";
  echo "\033[2K";

  $progress = $completedFrames / $frameLength;
  $percent = number_format($progress * 100, 2);
  echo "Progress: $completedFrames / $frameLength frames, $percent%\n";

  $elapsedSeconds = time() - $startTime;
  $from = new DateTime;
  $to = clone $from;
  $to = $to->add(new DateInterval("PT{$elapsedSeconds}S"));
  $diff = $from->diff($to);
  $elapsedTime = $diff->format('%h:%I:%S');
  if ($progress > 0) {
    $totalSeconds = floor($elapsedSeconds / $progress);
    $remainingSeconds = $totalSeconds - $elapsedSeconds;
    $from = new DateTime;
    $to = clone $from;
    $to = $to->add(new DateInterval("PT{$remainingSeconds}S"));
    $diff = $from->diff($to);
    $remainingTime = $diff->format('%h:%I:%S');
  } else {
    $remainingTime = 'Unknown';
  }
  echo "Elapsed time: $elapsedTime, Remaining time: $remainingTime\n";

  $charCount = max(floor($lineWidth * $progress) - 1, 0);
  $progressBar = str_repeat("=", $charCount);
  echo "{$progressBar}>";
}

