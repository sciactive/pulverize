#! /usr/bin/env php
<?php

date_default_timezone_set(@date_default_timezone_get());

echo "Pulverize - A multi-process rendering script for Blender VSE.\n";
echo "Version 1.1\n";
echo "Copyright 2017 Hunter Perrin\n";
echo "Licensed under GPL, just like Blender.\n";

if (!isset($argv[1])) {
  die(
      "\n" .
      "\nUsage: pulverize.php <blender_project_file> [<number_of_processes>] [<options>]" .
      "\n" .
      "\nExample: pulverize.php project.blend 6 '{\"keepTempFiles\":true,\"displayStdErr\":true}'" .
      "\n" .
      "\nOptions are given in JSON format as an object. (They should be flags, but that's a TODO for another time.)" .
      "\n  keepTempFiles defaults to false. When true, the frame range renders and the FFMPEG input script won't be deleted." .
      "\n  displayStdErr defaults to false. When true, StdErr stream from the blender processes will be displayed along with the Pulverize progress indicator. FFMPEG will also show warnings, not just errors." .
      "\n" .
      "\n"
  );
}

if (PHP_OS == "WINNT") {
  $lineWidth = (int) exec('powershell ^(Get-Host^).UI.RawUI.WindowSize.width');
} else {
  $lineWidth = (int) exec('tput cols');
}

$processCountArg = null;
$optionsArg = null;
$blenderFile = $argv[1];
if (isset($argv[2])) {
  $processCountArg = is_numeric($argv[2]) ? (int) $argv[2] : null;
  $optionsArg =
      is_object(json_decode($argv[2]))
          ? $argv[2]
          : ((isset($argv[3]) && is_object(json_decode($argv[3])))
              ? $argv[3]
              : 3);
}


$toolScript = __DIR__.'/pulverize_tool.py';
if (!file_exists($blenderFile)) {
  die("You didn't give me a valid Blender project file.\n");
}
if (!file_exists($toolScript)) {
  die("My tool script 'pulverize_tool.py' is missing.\n");
}

$shellBlenderFile = escapeshellarg($blenderFile);
$shellToolScript = escapeshellarg($toolScript);
if (PHP_OS == "WINNT") {
  $projectInfo = shell_exec("blender -b $shellBlenderFile -P $shellToolScript 2> nul");
} else {
  $projectInfo = shell_exec("blender -b $shellBlenderFile -P $shellToolScript 2>/dev/null");
}

preg_match('/^FRAMES: (\d+) (\d+)$/m', $projectInfo, $matches);
list($startFrame, $endFrame) = array_slice($matches, 1, 2);
preg_match('/^OUTPUTDIR: (.+)$/m', $projectInfo, $matches);
$outputDir = $matches[1];

// Add support for Blender's blend files path notation
if (substr($outputDir, 0, 2) === "//") {
  $path_parts = pathinfo($blenderFile);
  $blenderFilePath = realpath($path_parts['dirname']);

  $outputDir = str_replace("//", $blenderFilePath . DIRECTORY_SEPARATOR, $outputDir);
}

$shellOutputDir = escapeshellarg($outputDir);

if (!is_dir($outputDir)) {
  die("This script only works if your project's output is set to a directory. Please set it to a directory and try again.\n");
}

$frameLength = $endFrame - $startFrame + 1;
// Use half the number of logical processors reported by the system, with a max of 6.
// Added support for Windows 10 and MacOS
if (PHP_OS == "WINNT") {
  $processors = (int) shell_exec("echo %NUMBER_OF_PROCESSORS%");
} else if (PHP_OS == "Darwin") {
  $processors = (int) shell_exec("sysctl -n hw.ncpu");
} else {
  $processors = (int) shell_exec("cat /proc/cpuinfo | egrep \"^processor\" | wc -l");
}
$processCount = min(floor($processors / 2), 6);
if ($processCountArg && $processCountArg <= $processors) {
  $processCount = $processCountArg;
}
$processFrameCount = floor($frameLength / $processCount);
$remainderFrames = $frameLength % $processCount;

$options = [
  'keepTempFiles' => false,
  'displayStdErr' => false,
];
if ($optionsArg) {
  foreach (json_decode($optionsArg) as $key => $value) {
    $options[$key] = $value;
  }
}

echo <<<EOF

It looks like your machine has $processors logical processor(s). The default is to use half the number of logical processors reported by the system, with a max of 6.

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

  //$stdoutLogFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "pulverize_stdout$i.log";
  $stdoutLogFile = tempnam(sys_get_temp_dir(), 'PLV');
  //$stderrLogFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "pulverize_stderr$i.log";
  $stderrLogFile = tempnam(sys_get_temp_dir(), 'PLV');

  $handle = proc_open("blender -b $shellBlenderFile -s $s -e $e -o {$shellOutputDir}pulverize_frames_####### -a > $stdoutLogFile 2> $stderrLogFile", $descriptorspec, $pipes[$i]);
  usleep(250000);
  $stdoutHandle = fopen($stdoutLogFile, "r");
  $stderrHandle = fopen($stderrLogFile, "r");

  $processes[$i] = [
    'handle' => $handle,
    's' => $s,
    'e' => $e,
    'frame' => $s,
    'stdoutHandle' => $stdoutHandle,
    'stderrHandle' => $stderrHandle
  ];
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
    $stderr = stream_get_contents($curProcess['stderrHandle']);
    if ($stderr && $options['displayStdErr']) {
      echo "\n\n--------------- StdErr Processs: $curI\n";
      echo $stderr;
    }
    $stdout = stream_get_contents($curProcess['stdoutHandle']);
    if ($stdout) {
      preg_match('/^Append frame (\d+)/m', $stdout, $matches);
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

if (PHP_OS == "WINNT") {
  $fileLsOutput = shell_exec("cd $shellOutputDir && dir /b /a-d pulverize_frames_*");
} else {
  $fileLsOutput = shell_exec("cd $shellOutputDir && ls -1 pulverize_frames_*");
}

$files = explode("\n", trim($fileLsOutput));
if (!$files) {
  die("Something went wrong, and I can't find the video files. Check to see if the render worked.");
}
$fileList = "file ".implode("\nfile ", $files);
file_put_contents("$outputDir/pulverize_input_files.txt", $fileList);
$ext = explode(".", $files[0], 2)[1];
$startFramePadded = str_pad($startFrame, 7, '0', STR_PAD_LEFT);
$endFramePadded = str_pad($endFrame, 7, '0', STR_PAD_LEFT);
$ffmpegCommand = "ffmpeg" .
    ($options['displayStdErr'] ? "" : " -v error") .
    " -y -stats -f concat -i pulverize_input_files.txt -c copy $startFramePadded-$endFramePadded.$ext";
echo "$ $ffmpegCommand\n";
passthru("cd $shellOutputDir && $ffmpegCommand");

if (!$options['keepTempFiles']) {
  echo "\nRemoving temporary video files...\n";
  unlink("$outputDir/pulverize_input_files.txt");
  foreach ($files as $curFile) {
    unlink("$outputDir/$curFile");
  }
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
