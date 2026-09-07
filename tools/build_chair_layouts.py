"""Build the long-data and wide-data chair pictures from the red-chairs studio photograph.

The source photograph is a three by three grid of red chairs on a white studio backdrop. One chair is
cut out of it and re-placed on a backdrop rebuilt from the same photograph, twice. Both pictures use
the same ground-plane camera, so they differ only in the shape of the grid standing on it: the long
one is three columns running far back, the wide one three rows running far out to each side.

Changelog:
- 0.1.0 one shared camera for both pictures, each a grid of rows by columns in a 4:3 frame.
- 0.0.0 initial release.
"""

__author__ = 'yRocket'
__version__ = "0.1.0.2026.9.7"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

__all__ = ['read_backdrop_profile', 'cut_chair', 'paint_backdrop', 'place_grid', 'build_long', 'build_wide']

import argparse
import pathlib
from typing import Sequence

import numpy as np
from PIL import Image

SOURCE_NAME: str = 'red-chairs-grid-white.webp'
LONG_NAME: str = 'red-chairs-long-data.webp'
WIDE_NAME: str = 'red-chairs-wide-data.webp'

CHAIR_BOX: tuple = (946, 599, 1056, 812)      # the front-centre chair of the source, with its shadow
SOURCE_HORIZON: int = 281                     # row of the backdrop seam in the source
BACKDROP_COLUMNS: tuple = (80, 560)           # a chair-free band the backdrop profile is read from
ALPHA_SCALE: float = 30.0                     # luma drop, in levels, that counts as fully opaque

CANVAS_SIZE: tuple = (1600, 1200)             # 4:3, shared by both pictures
CANVAS_HORIZON: int = 330
NEAR_BASE: int = 1080                         # image row the nearest row of chairs stands on
NEAR_HEIGHT: int = 280                        # chair height at the nearest row
NEAR_PITCH: int = 360                         # column spacing at the nearest row
LONG_COLUMNS: int = 3
LONG_DEPTHS: tuple = tuple(row * 0.55 for row in range(11))    # ground distance of each row, in camera distances
WIDE_COLUMNS: int = 13
WIDE_DEPTHS: tuple = (0.0, 0.6, 1.6)          # widening, so no row hides behind the backs of the one in front
WIDE_TILT: int = -195                         # rows the wide scene is raised by, the camera aimed lower

WEBP_QUALITY: int = 92


def read_backdrop_profile(source: Image.Image = None, columns: tuple = BACKDROP_COLUMNS) -> np.ndarray:
    """Average colour of every source row over a chair-free band, shape (height, 3), float32."""
    band = np.asarray(source.convert('RGB'), dtype=np.float32)[:, columns[0]:columns[1], :]
    if band.size == 0:
        raise ValueError(f"backdrop band is empty; {columns=} does not intersect the source.")

    return band.mean(axis=1)


def cut_chair(source: Image.Image = None, box: tuple = CHAIR_BOX,
              alpha_scale: float = ALPHA_SCALE) -> Image.Image:
    """One chair with its shadow, as RGBA.

    The backdrop is estimated per row from the eight columns at each edge of the crop, and alpha is
    how far the pixel falls below it. Colour is un-mixed so that compositing the sprite back onto a
    backdrop of the same tone reproduces the crop exactly, shadow included.
    """
    crop = np.asarray(source.convert('RGB').crop(box), dtype=np.float32)
    margin = np.concatenate([crop[:, :8, :], crop[:, -8:, :]], axis=1)
    backdrop = np.median(margin, axis=1, keepdims=True)

    weights = np.array([0.299, 0.587, 0.114], dtype=np.float32)
    drop = backdrop @ weights - crop @ weights
    alpha = np.clip(drop / alpha_scale, 0.0, 1.0)[:, :, None]

    opaque = np.where(alpha > 0.0, alpha, 1.0)                    # the divisor only matters where alpha > 0
    colour = np.clip((crop - backdrop * (1.0 - alpha)) / opaque, 0.0, 255.0)

    rgba = np.concatenate([colour, alpha * 255.0], axis=2)
    return Image.fromarray(rgba.round().astype(np.uint8), mode='RGBA')


def paint_backdrop(profile: np.ndarray = None, size: tuple = CANVAS_SIZE,
                   horizon: int = CANVAS_HORIZON, source_horizon: int = SOURCE_HORIZON) -> Image.Image:
    """The source backdrop resampled onto a new canvas, with its seam moved to the given row.

    Above and below the seam are stretched separately so that the sky band and the floor keep their
    own gradients whatever the new aspect ratio is.
    """
    width, height = size
    if not 0 < horizon < height:
        raise ValueError(f"{horizon=} must lie inside the canvas of height {height}.")

    upper = np.linspace(0, source_horizon, num=horizon, endpoint=False)
    lower = np.linspace(source_horizon, len(profile) - 1, num=height - horizon)
    rows = np.concatenate([upper, lower])

    low = np.floor(rows).astype(int)
    high = np.minimum(low + 1, len(profile) - 1)
    frac = (rows - low)[:, None]
    column = profile[low] * (1.0 - frac) + profile[high] * frac

    canvas = np.repeat(column[:, None, :], repeats=width, axis=1)
    return Image.fromarray(canvas.round().astype(np.uint8), mode='RGB')


def place_grid(chair: Image.Image = None, profile: np.ndarray = None,
               depths: Sequence = None, columns: int = None, tilt: int = 0) -> Image.Image:
    """A rows-by-columns grid of chairs on the ground plane, seen by the shared camera.

    depths gives the ground distance of each row beyond the nearest one, in units of the distance to
    that nearest row, so a row at depth d is drawn at scale 1 / (1 + d) — what linear perspective does
    to a ground plane. The column spacing shrinks with the same scale, so the columns converge. Rows
    are drawn far to near, letting a near chair occlude the one behind it, and tilt moves the whole
    scene, horizon included, up or down the frame: it aims the same camera, it does not move it.
    """
    horizon = CANVAS_HORIZON + tilt
    canvas = paint_backdrop(profile=profile, horizon=horizon).convert('RGBA')
    span = NEAR_BASE - CANVAS_HORIZON
    centre_offsets = [index - (columns - 1) / 2 for index in range(columns)]

    for depth in reversed(depths):
        scale = 1.0 / (1.0 + depth)
        base_y = horizon + span * scale
        height = NEAR_HEIGHT * scale
        size = (max(1, round(chair.width * height / chair.height)), max(1, round(height)))
        scaled = chair.resize(size, resample=Image.LANCZOS)

        for offset in centre_offsets:
            centre_x = CANVAS_SIZE[0] / 2 + offset * NEAR_PITCH * scale
            canvas.alpha_composite(scaled, dest=(round(centre_x - size[0] / 2), round(base_y - size[1])))

    return canvas.convert('RGB')


def build_long(chair: Image.Image = None, profile: np.ndarray = None) -> Image.Image:
    """Three columns running far back — one row of a table after another."""
    return place_grid(chair=chair, profile=profile, depths=LONG_DEPTHS, columns=LONG_COLUMNS)


def build_wide(chair: Image.Image = None, profile: np.ndarray = None) -> Image.Image:
    """Three rows running past both edges of the frame — one column of a table after another."""
    return place_grid(chair=chair, profile=profile, depths=WIDE_DEPTHS, columns=WIDE_COLUMNS,
                      tilt=WIDE_TILT)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog=pathlib.Path(__file__).name,
        description=f"{pathlib.Path(__file__).name} {__version__}\n"
                    f"Build {LONG_NAME} and {WIDE_NAME} from {SOURCE_NAME}.",
        formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('-v', '--version', action='version', version=__version__)
    parser.add_argument('--image-folder', default='Images', metavar='FOLDER',
                        help='folder holding the source photograph (default: Images)')
    parser.add_argument('--output-folder', default=None, metavar='FOLDER',
                        help='folder the two pictures are written to (default: the image folder)')

    args = parser.parse_args()
    args.image_folder = pathlib.Path(args.image_folder)
    args.output_folder = args.image_folder if args.output_folder is None else pathlib.Path(args.output_folder)

    if not (args.image_folder / SOURCE_NAME).is_file():
        parser.error(f"source not found: {args.image_folder / SOURCE_NAME}")
    args.output_folder.mkdir(parents=True, exist_ok=True)

    return args


if __name__ == '__main__':
    arguments = parse_args()
    photograph = Image.open(arguments.image_folder / SOURCE_NAME)
    backdrop_profile = read_backdrop_profile(source=photograph)
    sprite = cut_chair(source=photograph)

    for name, picture in ((LONG_NAME, build_long(chair=sprite, profile=backdrop_profile)),
                          (WIDE_NAME, build_wide(chair=sprite, profile=backdrop_profile))):
        target = arguments.output_folder / name
        picture.save(target, format='WEBP', quality=WEBP_QUALITY, method=6)
        print(f"wrote {target} {picture.size[0]}x{picture.size[1]} {target.stat().st_size} bytes")
