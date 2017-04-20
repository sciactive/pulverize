# Pulverize
A multi-process rendering script for Blender VSE.

## What is it for?
Pulverize is a script for rendering video from Blender using multiple processes.

## Why is it useful?
If you have, say, an 8 core processor (like me), Blender's Video Sequence Editor will only use 1 of those when rendering. This is a huge waste of computing power, and makes render times intolerably slow. This script will render the video in parts, using one process for each part. This means renders are **8 times faster** with a machine like mine. You get all the benefits of multithreaded rendering in Blender without Blender VSE actually being multithreaded.

## What are the dependencies?
You will need:
* Blender (duh)
  * For best results: Output as an MPEG, and encode with MPEG-4 under Format, and H.264 under Codec.
* FFMPEG (for putting all the video parts together)
* PHP
* Python
* Linux, or a compatible system

## How do I use it?
Download this repository, and extract it to your computer somewhere. You can make a link to it to run it easily like this:

    sudo ln -s path/to/pulverize.php /usr/bin/pulverize

Usage:

    pulverize.php <blender_project_file> [<number_of_processes>] [<options>]

Example:

    pulverize.php project.blend 6 '{\"keepTempFiles\":true,\"displayStdErr\":true}'

Options are given in JSON format as an object. (They should be flags, but that's a TODO for another time.)

* keepTempFiles defaults to false. When true, the frame range renders and the FFMPEG input script won't be deleted.
* displayStdErr defaults to false. When true, StdErr stream from the blender processes will be displayed along with the Pulverize progress indicator. FFMPEG will also show warnings, not just errors.

### There's now a Python version, pulverize.py, available for use (Thanks, jpwarren!):
Some differences from pulverize.php:

It uses argparse to make it easy to add new commandline arguments

    -w or --workers option to specify how many processes you want to use for rendering. Default value is the same as pulverize.php.
    --dry-run option to test things without actually rendering or concatenating
    --render-only option to just do the render of tempfiles, but not the concat
    --concat-only option to concat, but skip the render stage

It doesn't show progress of the rendering subprocesses, so if you want that, best to use pulverize.php instead.

## Why is it PHP?
Cause PHP is a badass scripting language. Also, I don't know Python.

## Why is the code so unconventional (read messy)?
Cause I wrote this for me. This is how I code when no one's watching, and judging me for that would be wrong. ;)
