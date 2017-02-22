# Pulverize
A multi-process rendering script for Blender VSE.

## What is it for?
Pulverize is a script for rendering video from Blender using multiple processes.

## Why is it useful?
If you have, say, an 8 core processor (like me), Blender's Video Sequence Editor will only use 1 of those when rendering. This is a huge waste of computing power, and makes render times intolerably slow. This script will render the video in parts, using one process for each part. This means renders are **8 times faster** with a machine like mine. You get all the benefits of multithreaded rendering in Blender without Blender VSE actually being multithreaded.

## What are the dependencies?
You will need:
* Blender (duh)
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

## Why is it PHP?
Cause PHP is a badass scripting language. Also, I don't know Python.


