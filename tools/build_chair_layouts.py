"""Build the long-data and wide-data chair pictures from the red-chairs studio photograph.

The source photograph is a three by three grid of red chairs on a white studio backdrop. One chair
is cut out of it and re-placed on a backdrop rebuilt from the same photograph, twice: once as a
column receding toward the horizon, and once as a row running past both edges of the frame.

Changelog:
- 0.0.0 initial release.
"""

__author__ = 'yRocket'
__version__ = "0.0.0.2026.9.7"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

__all__ = ['read_backdrop_profile', 'cut_chair', 'paint_backdrop', 'build_long', 'build_wide']

import argparse
import pathlib
import sys
from typing import Union

import numpy as np
from PIL import Image

SOURCE_NAME: str = 'red-chairs-grid-white.webp'
LONG_NAME: str = 'red-chairs-long-data.webp'
WIDE_NAME: str = 'red-chairs-wide-data.webp'

CHAIR_BOX: tuple = (946, 599, 1056, 812)      # the front-centre chair of the source, with its shadow
SOURCE_HORIZON: int = 281                     # row of the backdrop seam in the source
BACKDROP_COLUMNS: tuple = (80, 560)           # a chair-free band the backdrop profile is read from
ALPHA_SCALE: float = 30.0                     # luma drop, in levels, that counts as fully opaque

LONG_SIZE: tuple = (1050, 1500)
LONG_HORIZON: int = 430
LONG_NEAR_BASE: int = 1330                    # image row the nearest chair stands on
LONG_NEAR_HEIGHT: int = 340
LONG_NEAR_X: int = 430                        # column the nearest chair is centred on
LONG_VANISH_X: int = 700                      # column the receding line converges to
LONG_COUNT: int = 16
LONG_DEPTH_STEP: float = 0.62                 # ground spacing as a fraction of the distance to the first chair

WIDE_SIZE: tuple = (2400, 900)
WIDE_HORIZON: int = 250
WIDE_BASE: int = 760
WIDE_HEIGHT: int = 360
WIDE_COUNT: int = 14
WIDE_PITCH: int = 215

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


def paint_backdrop(profile: np.ndarray = None, size: tuple = None, horizon: int = None,
                   source_horizon: int = SOURCE_HORIZON) -> Image.Image:
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


def _place(canvas: Image.Image = None, chair: Image.Image = None, centre_x: float = None,
           base_y: float = None, height: float = None) -> None:
    """Paste one chair scaled to the given height, standing on base_y and centred on centre_x."""
    scale = height / chair.height
    size = (max(1, round(chair.width * scale)), max(1, round(height)))
    scaled = chair.resize(size, resample=Image.LANCZOS)
    canvas.alpha_composite(scaled, dest=(round(centre_x - size[0] / 2), round(base_y - size[1])))


def build_long(chair: Image.Image = None, profile: np.ndarray = None) -> Image.Image:
    """Chairs receding toward the horizon in one column — one row of a table after another."""
    canvas = paint_backdrop(profile=profile, size=LONG_SIZE, horizon=LONG_HORIZON).convert('RGBA')
    span = LONG_NEAR_BASE - LONG_HORIZON

    for index in reversed(range(LONG_COUNT)):                     # far to near, so the near chair occludes
        scale = 1.0 / (1.0 + index * LONG_DEPTH_STEP)             # a ground plane seen in linear perspective
        _place(canvas=canvas, chair=chair,
               centre_x=LONG_VANISH_X + (LONG_NEAR_X - LONG_VANISH_X) * scale,
               base_y=LONG_HORIZON + span * scale, height=LONG_NEAR_HEIGHT * scale)

    return canvas.convert('RGB')


def build_wide(chair: Image.Image = None, profile: np.ndarray = None) -> Image.Image:
    """Chairs in one row running past both edges of the frame — one column of a table after another."""
    canvas = paint_backdrop(profile=profile, size=WIDE_SIZE, horizon=WIDE_HORIZON).convert('RGBA')
    first = WIDE_SIZE[0] / 2 - WIDE_PITCH * (WIDE_COUNT - 1) / 2

    for index in range(WIDE_COUNT):
        _place(canvas=canvas, chair=chair, centre_x=first + index * WIDE_PITCH,
               base_y=WIDE_BASE, height=WIDE_HEIGHT)

    return canvas.convert('RGB')


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
