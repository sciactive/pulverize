import bpy

scene = bpy.context.scene
print("FRAMES: %d %d" % (scene.frame_start, scene.frame_end))
print("OUTPUTDIR: %s" % (scene.render.filepath))
