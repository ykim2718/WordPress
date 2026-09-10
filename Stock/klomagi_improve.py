"""Search for a klomagi variant whose equity beats the S&P 500 index over the same sample.

The base rule loses to the index because 174 trades in five years leave the account holding about
six names, so this script varies the three things that change that: how many signals the thresholds
admit, when a position is closed, and where the capital sits while too few signals are open.

Changelog:
- 0.0.0.2026.9.10: initial release
- 0.1.0.2026.9.10: add the random entry control portfolio for the best variant
"""

__author__ = 'yRocket'
__version__ = "0.1.0.2026.9.10"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

__all__ = [
    'StopRule',
    'IdleRule',
    'Variant',
    'build_variant_trades',
    'build_control_trades',
    'variant_curve',
    'run_variants',
    'plot_variants',
]

import argparse
import enum
import json
import pathlib
import sys
from dataclasses import dataclass

import matplotlib
import numpy as np
import pandas as pd
from tqdm import tqdm

from klomagi_backtest import (BASE_FONT_SIZE, EQUITY_FIGSIZE, MA_WINDOWS, REFERENCE_WIDTH, RuleParams, TradeColumn,
                              build_indicators, find_signals, index_curve, load_index_level, load_prices,
                              portfolio_stats, position_returns)

matplotlib.use('Agg')
import matplotlib.pyplot as plt  # noqa: E402  matplotlib requires the backend to be set before pyplot

TRAIL_WINDOW: int = 20   # the moving average the trailing stop follows


class StopRule(enum.StrEnum):
    """Where a position is closed before the holding limit."""

    BAND = enum.auto()      # close below the bundle floor of the signal day
    TRAIL = enum.auto()     # close below the trailing TRAIL_WINDOW day moving average
    NONE = enum.auto()      # no stop, the holding limit alone closes the position


class IdleRule(enum.StrEnum):
    """Where the capital sits while fewer than the target number of positions are open."""

    CASH = enum.auto()
    INDEX = enum.auto()


@dataclass(frozen=True)
class Variant:
    """One portfolio rule to test.

    min_names: the number of slots the capital is split into, so an account holding fewer positions
    than this puts the rest where idle says instead of concentrating it in what is open.
    """

    name: str
    params: RuleParams
    stop: StopRule
    min_names: int
    idle: IdleRule


def _exit_position(indicators: pd.DataFrame, entry_pos: int, params: RuleParams, stop: StopRule) -> tuple:
    """Walk one position forward under the given stop and return (exit_pos, exit_price).

    Every stop is checked on closes and filled at the next open, which is the earliest price a
    close based rule can trade at.
    """
    close = indicators['close'].to_numpy()
    open_ = indicators['open'].to_numpy()
    last_pos = len(close) - 1
    limit_pos = min(entry_pos + params.hold_days, last_pos)

    if stop == StopRule.BAND:
        level = np.full(len(close), indicators['band_low'].to_numpy()[entry_pos - 1])
    elif stop == StopRule.TRAIL:
        level = indicators['trail'].to_numpy()
    elif stop == StopRule.NONE:
        level = np.full(len(close), -np.inf)
    else:
        raise ValueError(f"unknown stop rule {stop}")

    for pos in range(entry_pos, limit_pos + 1):
        if close[pos] < level[pos]:
            if pos == last_pos:
                return pos, close[pos]
            return pos + 1, open_[pos + 1]

    if limit_pos >= last_pos:
        return last_pos, close[last_pos]
    return limit_pos + 1, open_[limit_pos + 1]


def build_variant_trades(prices: pd.DataFrame, params: RuleParams, stop: StopRule,
                         progress: bool = True) -> pd.DataFrame:
    """Run the signal arm of one rule over every ticker under the given stop.

    Returns a pd.DataFrame with a RangeIndex and the columns of TradeColumn except EXCESS_PCT,
    EXIT_REASON and ARM, which this search does not use.
    """
    warmup = max(MA_WINDOWS)
    groups = prices.groupby('ticker', sort=True)
    bar = tqdm(groups, ncols=100, unit='ticker', disable=not progress)
    trades: list = []

    for ticker, frame in bar:
        bar.set_description(f"Scanning {ticker}")
        if len(frame) <= warmup + 2:
            continue
        indicators = build_indicators(prices=frame)
        indicators['trail'] = indicators['close'].rolling(window=TRAIL_WINDOW).mean()
        open_ = indicators['open'].to_numpy()
        dates = indicators['date'].to_numpy()
        busy_until = -1

        for signal_pos in find_signals(indicators=indicators, params=params):
            entry_pos = signal_pos + 1                   # the signal is only tradable at the next open
            if entry_pos <= busy_until or entry_pos >= len(open_):
                continue
            exit_pos, exit_price = _exit_position(indicators=indicators, entry_pos=entry_pos,
                                                  params=params, stop=stop)
            trades.append({
                TradeColumn.TICKER: ticker,
                TradeColumn.ENTRY_DATE: dates[entry_pos],
                TradeColumn.EXIT_DATE: dates[exit_pos],
                TradeColumn.ENTRY_PRICE: open_[entry_pos],
                TradeColumn.EXIT_PRICE: exit_price,
                TradeColumn.HOLD_DAYS: int(exit_pos - entry_pos),
                TradeColumn.RETURN_PCT: 100.0 * (exit_price / open_[entry_pos] - 1.0),
            })
            busy_until = exit_pos

    if not trades:
        raise ValueError(f"no trade was produced by {params} under stop {stop}; loosen the thresholds")
    return pd.DataFrame(trades)


def build_control_trades(prices: pd.DataFrame, params: RuleParams, stop: StopRule,
                         signal_trades: pd.DataFrame, seed: int) -> pd.DataFrame:
    """Rebuild one variant's trades with the entry days drawn at random instead of from the signal.

    Each ticker gets as many entries as the signal gave it, drawn uniformly from the days where the
    bundle is defined, and they are closed by the same stop and holding limit. What the variant owes
    to its entry condition is the gap between this portfolio and the signal portfolio.

    Returns a pd.DataFrame shaped like build_variant_trades().
    """
    rng = np.random.default_rng(seed)
    warmup = max(MA_WINDOWS)
    wanted = signal_trades.groupby(TradeColumn.TICKER).size().to_dict()
    trades: list = []

    for ticker, frame in prices.groupby('ticker', sort=True):
        if ticker not in wanted or len(frame) <= warmup + 2:
            continue
        indicators = build_indicators(prices=frame)
        indicators['trail'] = indicators['close'].rolling(window=TRAIL_WINDOW).mean()
        open_ = indicators['open'].to_numpy()
        dates = indicators['date'].to_numpy()
        pool = np.arange(warmup, len(indicators) - 1)
        drawn = np.sort(rng.choice(pool, size=min(wanted[ticker], len(pool)), replace=False))
        busy_until = -1

        for entry_pos in drawn + 1:
            if entry_pos <= busy_until or entry_pos >= len(open_):
                continue
            exit_pos, exit_price = _exit_position(indicators=indicators, entry_pos=int(entry_pos),
                                                  params=params, stop=stop)
            trades.append({
                TradeColumn.TICKER: ticker,
                TradeColumn.ENTRY_DATE: dates[entry_pos],
                TradeColumn.EXIT_DATE: dates[exit_pos],
                TradeColumn.ENTRY_PRICE: open_[entry_pos],
                TradeColumn.EXIT_PRICE: exit_price,
                TradeColumn.HOLD_DAYS: int(exit_pos - entry_pos),
                TradeColumn.RETURN_PCT: 100.0 * (exit_price / open_[entry_pos] - 1.0),
            })
            busy_until = exit_pos

    if not trades:
        raise ValueError(f"the control produced no trade for {params} under stop {stop}")
    return pd.DataFrame(trades)


def variant_curve(prices: pd.DataFrame, trades: pd.DataFrame, variant: Variant,
                  index_return: pd.Series) -> pd.DataFrame:
    """Build the daily curve of one variant.

    The capital is split into max(open positions, min_names) slots. Slots without a position earn
    nothing under IdleRule.CASH and the index return under IdleRule.INDEX.

    Returns a pd.DataFrame indexed by 'date' with the columns
    ['daily_return', 'open_positions', 'equity'].
    """
    calendar, total, count = position_returns(prices=prices, trades=trades)
    aligned = index_return.reindex(pd.Index(calendar, name='date'))
    if aligned.isna().any():
        raise ValueError(f"the index return misses {int(aligned.isna().sum())} of the {len(calendar)} sample days")

    slots = np.maximum(count, float(variant.min_names))
    invested = total / slots
    idle_weight = 1.0 - count / slots
    if variant.idle == IdleRule.CASH:
        idle = np.zeros(len(calendar))
    elif variant.idle == IdleRule.INDEX:
        idle = idle_weight * aligned.to_numpy()
    else:
        raise ValueError(f"unknown idle rule {variant.idle}")

    daily = invested + idle
    return pd.DataFrame({'daily_return': daily, 'open_positions': count.astype(int),
                         'equity': (1.0 + daily).cumprod()}, index=pd.Index(calendar, name='date'))


def run_variants(prices: pd.DataFrame, variants: list, index_return: pd.Series) -> tuple:
    """Run every variant, reusing one trade table per (params, stop) pair.

    Returns (table, curves) where table is a pd.DataFrame with a RangeIndex and the columns
    ['name', 'conv_max', 'vol_mult', 'hold_days', 'stop', 'min_names', 'idle', 'trades'] plus every
    key of portfolio_stats(), and curves maps the variant name to its curve.
    """
    trade_cache: dict = {}
    rows: list = []
    curves: dict = {}
    bar = tqdm(variants, ncols=100, unit='variant')

    for variant in bar:
        bar.set_description(f"Testing {variant.name}")
        key = (variant.params, variant.stop)
        if key not in trade_cache:
            trade_cache[key] = build_variant_trades(prices=prices, params=variant.params,
                                                    stop=variant.stop, progress=False)
        trades = trade_cache[key]
        curve = variant_curve(prices=prices, trades=trades, variant=variant, index_return=index_return)
        curves[variant.name] = curve
        rows.append({'name': variant.name, 'conv_max': variant.params.conv_max,
                     'vol_mult': variant.params.vol_mult, 'hold_days': variant.params.hold_days,
                     'stop': str(variant.stop), 'min_names': variant.min_names, 'idle': str(variant.idle),
                     'trades': int(len(trades)), **portfolio_stats(curve=curve)})

    return pd.DataFrame(rows), curves


def plot_variants(curves: dict, benchmark: pd.DataFrame, fig_path: pathlib.Path) -> None:
    """Draw the benchmark and the given variant curves on one axis."""
    font_size = BASE_FONT_SIZE * EQUITY_FIGSIZE[0] / REFERENCE_WIDTH
    colors = list(matplotlib.colors.TABLEAU_COLORS.values())
    fig, axis = plt.subplots(nrows=1, ncols=1, figsize=EQUITY_FIGSIZE)

    stats = portfolio_stats(curve=benchmark)
    axis.plot(benchmark.index, benchmark['equity'], color='black', linewidth=1.6,
              label=f"S&P 500 index (total {stats['total_return_pct']:.1f}%, CAGR {stats['cagr_pct']:.1f}%)")
    for index, (name, curve) in enumerate(curves.items()):
        stats = portfolio_stats(curve=curve)
        axis.plot(curve.index, curve['equity'], color=colors[index], linewidth=1.1,
                  label=f"{name} (total {stats['total_return_pct']:.1f}%, CAGR {stats['cagr_pct']:.1f}%)")

    axis.axhline(1.0, color='black', linewidth=0.8)
    axis.set_xlabel('Date', fontsize=font_size)
    axis.set_ylabel('Equity, starting at 1.0', fontsize=font_size)
    axis.tick_params(labelsize=font_size)
    axis.grid(alpha=0.25)
    axis.legend(fontsize=font_size, loc='upper left')

    fig.subplots_adjust(left=0.08, right=0.98, bottom=0.14, top=0.96)
    fig_path.parent.mkdir(parents=True, exist_ok=True)
    fig.savefig(fig_path, dpi=300)
    plt.close(fig)


def build_variants(min_names: int) -> list:
    """Build the search grid: three threshold sets by three stops by two places for idle capital."""
    thresholds = {
        'base': RuleParams(conv_max=0.03, vol_mult=2.0, body_min=0.03, hold_days=60),
        'loose': RuleParams(conv_max=0.05, vol_mult=1.5, body_min=0.03, hold_days=60),
        'loose-long': RuleParams(conv_max=0.05, vol_mult=1.5, body_min=0.03, hold_days=120),
    }
    variants: list = []
    for label, params in thresholds.items():
        for stop in (StopRule.BAND, StopRule.TRAIL, StopRule.NONE):
            for idle, slots in ((IdleRule.CASH, 1), (IdleRule.INDEX, min_names)):
                variants.append(Variant(name=f"{label}/{stop}/{idle}", params=params, stop=stop,
                                        min_names=slots, idle=idle))
    return variants


def parse_args() -> argparse.Namespace:
    """Parse the command line options."""
    parser = argparse.ArgumentParser(
        prog=pathlib.Path(__file__).name,
        description=f"{pathlib.Path(__file__).name} {__version__}\n"
                    f"Search for a klomagi variant that beats the S&P 500 index.",
        formatter_class=argparse.RawTextHelpFormatter)
    parser.add_argument('-v', '--version', action='version', version=f"{pathlib.Path(__file__).name} {__version__}")
    parser.add_argument('--data-csv', type=str, required=True,
                        help="daily OHLCV csv; downloaded from the public dataset when absent")
    parser.add_argument('--index-csv', type=str, required=True,
                        help="daily S&P 500 index csv; downloaded from the public dataset when absent")
    parser.add_argument('--output-folder', type=str, required=True,
                        help="root folder of every output file")
    parser.add_argument('--min-names', type=int, default=20,
                        help="slots the capital is split into when the idle capital goes to the index")
    parser.add_argument('--seed', type=int, default=20260910,
                        help="seed of the random entry control run on the best variant")

    if len(sys.argv) == 1:
        parser.print_help()
        sys.exit(0)

    args = parser.parse_args()
    args.data_csv = pathlib.Path(args.data_csv)
    args.index_csv = pathlib.Path(args.index_csv)
    args.output_folder = pathlib.Path(args.output_folder)
    if args.min_names < 1:
        parser.error(f"--min-names must be positive; got {args.min_names}")
    return args


if __name__ == '__main__':
    cli = parse_args()
    cli.output_folder.mkdir(parents=True, exist_ok=True)

    price_table = load_prices(csv_path=cli.data_csv)
    calendar_days = np.sort(price_table['date'].unique())
    index_level = load_index_level(csv_path=cli.index_csv, calendar=calendar_days)
    benchmark_curve = index_curve(index_level=index_level)
    benchmark_stats = portfolio_stats(curve=benchmark_curve)
    print(f"benchmark S&P 500: total {benchmark_stats['total_return_pct']}%, "
          f"CAGR {benchmark_stats['cagr_pct']}%", flush=True)

    table, variant_curves = run_variants(prices=price_table, variants=build_variants(min_names=cli.min_names),
                                         index_return=index_level.pct_change().fillna(0.0))
    table['beats_index'] = table['cagr_pct'] > benchmark_stats['cagr_pct']
    table = table.sort_values('cagr_pct', ascending=False).reset_index(drop=True)
    table.to_csv(cli.output_folder / 'variants.csv', index=False)

    best = next(variant for variant in build_variants(min_names=cli.min_names)
                if variant.name == table.loc[0, 'name'])
    best_trades = build_variant_trades(prices=price_table, params=best.params, stop=best.stop, progress=False)
    control_trades = build_control_trades(prices=price_table, params=best.params, stop=best.stop,
                                          signal_trades=best_trades, seed=cli.seed)
    control_curve = variant_curve(prices=price_table, trades=control_trades, variant=best,
                                  index_return=index_level.pct_change().fillna(0.0))
    control_stats = portfolio_stats(curve=control_curve)
    variant_curves[f"{best.name} random entry"] = control_curve
    print(f"control for {best.name}: total {control_stats['total_return_pct']}%, "
          f"CAGR {control_stats['cagr_pct']}%, {len(control_trades)} trades", flush=True)

    winners = table[table['beats_index']]['name'].tolist()
    if not winners:
        print("no variant beat the index; the table holds every result", flush=True)
    shown = list(table['name'].head(3)) + [f"{best.name} random entry"]
    plot_variants(curves={name: variant_curves[name] for name in shown},
                  benchmark=benchmark_curve, fig_path=cli.output_folder / 'fig3.png')

    report = {'benchmark': benchmark_stats, 'variants_beating_index': winners,
              'best_variant': best.name, 'best_variant_control': control_stats,
              'table': table.to_dict(orient='records')}
    (cli.output_folder / 'variants.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(table.to_string(index=False), flush=True)
