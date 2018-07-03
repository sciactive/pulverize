import bpy
scene = bpy.context.scene


def get_scene_data():
    print("FRAMES: %d %d" % (scene.frame_start, scene.frame_end))
    print("OUTPUTDIR: %s" % (scene.render.filepath))


def set_audio_only():
    sed = scene.sequence_editor
    sequences = sed.sequences_all

    for strip in sequences:
        if strip.type != "SOUND":
            strip.mute = True
