#!/usr/bin/python

""" A script to do multi-process Blender VSE rendering
"""

import argparse
import os
import multiprocessing
import subprocess
import math
import glob

import logging
logging.basicConfig(level=logging.DEBUG)
log = logging.getLogger("pulverize")

import pdb

# We can get the number of CPUs in the system from multiprocessing
CPUS = min(int(multiprocessing.cpu_count() / 2), 6)

UTIL_SCRIPT="pulverize_tool.py"

def get_project_data(args):
    
    realpath = os.path.dirname(os.path.realpath(__file__))
    utilfile = os.path.join(realpath, UTIL_SCRIPT)
    data = subprocess.check_output(['blender', '-b', args.blendfile, '-P', utilfile])
    
    lines = data.split('\n')
    frameinfo = lines[0].split()
    # log.debug("frameinfo: %s", frameinfo)
    outdirinfo = lines[1].split()
    # log.debug("outputdir: %s", outdirinfo)

    frame_start = int(frameinfo[1])
    frame_end = int(frameinfo[2])
    outdir = outdirinfo[1]

    return(frame_start, frame_end, outdir)

    pass

def render_chunks(args, frame_start, frame_end, outdir):
    """
    Divide render into even sized chunks
    """
    log.info("Render frames from %s to %s", frame_start, frame_end)
    total_frames = frame_end - frame_start
    # log.debug("total frames: %s", total_frames)
    chunk_frames = int(math.floor(total_frames / args.workers))
    # log.debug("chunk_frames: %s", chunk_frames)

    processes = []
    # Figure out the frame ranges for each worker.
    # The last worker will need to render a few extra
    # frames if the total number of frames doesn't Divide
    # neatly, but this is usually a relatively small number
    # of extra frames, so we don't need to create an entirely
    # new worker to work on it.
    for i in range(args.workers):
        log.debug("Setting params for worker %d", i)
        w_start_frame = frame_start + (i*chunk_frames)
        if i == args.workers - 1:
            # Last worker takes up extra frames
            w_end_frame = frame_end
        else:
            w_end_frame = w_start_frame + chunk_frames - 1

        log.debug("worker %d rendering frames %d to %d", i, w_start_frame, w_end_frame)

        # Set a worker to work on this frame range
        p = multiprocessing.Process(target=render_proc, args=(args, w_start_frame, w_end_frame, outdir))
        processes.append(p)
        p.start()
        log.info("Started render process %d with pid %d", i, p.pid)
        pass

    
    # wait for results
    for i, p in enumerate(processes):
        log.debug("Waiting for proc %d", i)
        p.join()

    log.info("Render processes complete.")

def render_proc(args, start_frame, end_frame, outdir):
    """
    Render a chunk of the blender file.
    """
    outfilepath = os.path.join(outdir, 'pulverize_frames_#######')
    params = ['blender', '-b', args.blendfile,
                          '-s', '%s' % start_frame,
                          '-e', '%s' % end_frame,
                          '-o', outfilepath, '-a']
    log.debug("Render command: %s", params)
    if not args.dry_run:
        proc = subprocess.Popen(params, stdin=subprocess.PIPE, stdout=subprocess.PIPE)
        stdoutdata, stderrdata = proc.communicate()

    pass

def join_chunks(args, outdir):
    """
    Concatenate the video chunks together with ffmpeg
    """
    # Which files do we need to join?
    chunk_glob = os.path.join(outdir, 'pulverize_frames_*')
    chunk_files = sorted(glob.glob(chunk_glob))
    # log.debug("file list for %s is: %s", chunk_glob, chunk_files)

    file_list = os.path.join(outdir, 'pulverize_input_files.txt')

    with open(file_list, 'w') as fp:
        fp.write('\n'.join(["file %s" % x for x in chunk_files]))
    filebase, ext = os.path.splitext(os.path.basename(args.blendfile))
    outbase, outext = os.path.splitext(os.path.basename(chunk_files[0]))
    outfile = '%s%s' % (filebase, outext)
    log.info("Joining parts into: %s", outfile)
    params = ['ffmpeg', '-stats', '-f', 'concat',
            '-safe', '0',
            '-i', file_list,
            '-c', 'copy', outfile]
    log.debug("ffmpeg params: %s", params)
    if not args.dry_run:
        subprocess.check_call(params)

if __name__ == '__main__':

    ap = argparse.ArgumentParser(description="Multi-process Blender VSE rendering",
                                 formatter_class=argparse.ArgumentDefaultsHelpFormatter)
    
    ap.add_argument('-w', '--workers', type=int, default=CPUS, help="Number of workers in the pool.")
    ap.add_argument('--concat-only', action='store_true', default=False, help="Don't render new sections, just concat existing ones.")
    ap.add_argument('--render-only', action='store_true', default=False, help="Render sections, but don't concat.")
    ap.add_argument('--dry-run', action='store_true', default=False, help="Do everything but the complex, time-consuming subprocesses.")

    ap.add_argument('blendfile', help="Blender project file to render.")
    args = ap.parse_args()

    frame_start, frame_end, outdir = get_project_data(args)

    if not args.concat_only:
        render_chunks(args, frame_start, frame_end, outdir)

    if not args.render_only:
        join_chunks(args, outdir)
