"""Backtest of the klomagi moving average convergence breakout rule on daily OHLCV data.

The rule buys the day after a tight bundle of SMA(5, 20, 60, 120) is broken upward by a wide
bullish candle on heavy volume, and sells on a close below the bundle or after a holding limit.
A random entry control with the identical exit rule is run on the same tickers, so the reported
edge is the difference between the signal and an arbitrary entry, not the market drift.

Changelog:
- 0.0.0.2026.9.10: initial release
- 0.1.0.2026.9.10: add the daily equity curve, its cumulative and annualized return, and Fig 2
- 0.2.0.2026.9.10: compare against the S&P 500 index read from a second daily csv
- 0.2.1.2026.9.10: split position_returns out of equity_curve so other portfolio rules can reuse it
"""

__author__ = 'yRocket'
__version__ = "0.2.1.2026.9.10"  # Semantic Versioning: Major.Minor.Patch.Date(YYYY.M.D)

__all__ = [
    'RuleParams',
    'TradeColumn',
    'load_prices',
    'build_indicators',
    'build_market_index',
    'add_excess_return',
    'find_signals',
    'run_trades',
    'summarize',
    'position_returns',
    'equity_curve',
    'load_index_level',
    'index_curve',
    'portfolio_stats',
    'run_grid',
    'plot_results',
    'plot_equity',
]

import argparse
import enum
import json
import pathlib
import sys
import urllib.request
from dataclasses import dataclass, asdict

import matplotlib
import numpy as np
import pandas as pd
from tqdm import tqdm

matplotlib.use('Agg')
import matplotlib.pyplot as plt  # noqa: E402  matplotlib requires the backend to be set before pyplot

DATA_URL: str = 'https://raw.githubusercontent.com/plotly/datasets/master/all_stocks_5yr.csv'
INDEX_URL: str = 'https://raw.githubusercontent.com/vega/vega-datasets/main/data/sp500-2000.csv'
MA_WINDOWS: tuple = (5, 20, 60, 120)
VOLUME_WINDOW: int = 20
CONTROL_DRAWS_PER_SIGNAL: int = 10   # the control is oversampled so its mean is the tighter of the two
FIGSIZE: tuple = (15.0, 4.6)
EQUITY_FIGSIZE: tuple = (10.0, 5.0)
REFERENCE_WIDTH: float = 9.0     # the width BASE_FONT_SIZE was chosen for
BASE_FONT_SIZE: float = 9.0
TRADING_DAYS_PER_YEAR: int = 252


class TradeColumn(enum.StrEnum):
    """Column names of the trade table, used by both the trade runner and the plots."""

    TICKER = enum.auto()
    ENTRY_DATE = enum.auto()
    EXIT_DATE = enum.auto()
    ENTRY_PRICE = enum.auto()
    EXIT_PRICE = enum.auto()
    HOLD_DAYS = enum.auto()
    RETURN_PCT = enum.auto()
    EXCESS_PCT = enum.auto()
    EXIT_REASON = enum.auto()
    ARM = enum.auto()


class ExitReason(enum.StrEnum):
    """Why a position was closed."""

    STOP = enum.auto()
    HOLD_LIMIT = enum.auto()
    DATA_END = enum.auto()


class Arm(enum.StrEnum):
    """Which entry rule produced the trade."""

    SIGNAL = enum.auto()
    RANDOM = enum.auto()


@dataclass(frozen=True)
class RuleParams:
    """One parameter set of the klomagi rule.

    conv_max: highest allowed (band_high - band_low) / band_low on the breakout day.
    vol_mult: lowest allowed volume as a multiple of the trailing VOLUME_WINDOW mean volume.
    body_min: lowest allowed (close - open) / open of the breakout candle.
    hold_days: number of trading days a position is held when the stop is not touched.
    """

    conv_max: float = 0.03
    vol_mult: float = 2.0
    body_min: float = 0.03
    hold_days: int = 60


def load_prices(csv_path: pathlib.Path, url: str = DATA_URL) -> pd.DataFrame:
    """Read the daily OHLCV table, downloading it once when the file is absent.

    Returns a pd.DataFrame with a RangeIndex and columns
    ['date', 'open', 'high', 'low', 'close', 'volume', 'ticker'], sorted by ticker then date.
    """
    if not csv_path.exists():
        csv_path.parent.mkdir(parents=True, exist_ok=True)
        print(f"downloading {url} -> {csv_path}", flush=True)
        urllib.request.urlretrieve(url, csv_path)

    frame = pd.read_csv(csv_path, parse_dates=['date'])
    expected = {'date', 'open', 'high', 'low', 'close', 'volume', 'Name'}
    missing = expected - set(frame.columns)
    if missing:
        raise ValueError(f"{csv_path} lacks columns {sorted(missing)}; got {sorted(frame.columns)}")

    frame = frame.rename(columns={'Name': 'ticker'})
    dropped = frame[['open', 'high', 'low', 'close', 'volume']].isna().any(axis=1).sum()
    if dropped:
        print(f"dropping {dropped} rows with missing OHLCV out of {len(frame)}", flush=True)
    frame = frame.dropna(subset=['open', 'high', 'low', 'close', 'volume'])
    return frame.sort_values(['ticker', 'date']).reset_index(drop=True)


def build_indicators(prices: pd.DataFrame) -> pd.DataFrame:
    """Add the moving average bundle and the trailing volume mean of one ticker.

    Returns a pd.DataFrame with a RangeIndex and the columns of the input plus
    ['band_high', 'band_low', 'spread', 'vol_mean', 'body'].
    """
    frame = prices.reset_index(drop=True).copy()
    bundle = pd.DataFrame({f"ma{w}": frame['close'].rolling(window=w).mean() for w in MA_WINDOWS})
    frame['band_high'] = bundle.max(axis=1)
    frame['band_low'] = bundle.min(axis=1)
    frame['spread'] = (frame['band_high'] - frame['band_low']) / frame['band_low']
    frame['vol_mean'] = frame['volume'].shift(1).rolling(window=VOLUME_WINDOW).mean()
    frame['body'] = (frame['close'] - frame['open']) / frame['open']
    return frame


def build_market_index(prices: pd.DataFrame) -> pd.Series:
    """Build the equal weight index of the universe.

    Returns a pd.Series named 'index_level' indexed by 'date', starting at 1.0 on the first date.
    """
    daily = prices.pivot_table(index='date', columns='ticker', values='close').sort_index()
    mean_return = daily.pct_change().mean(axis=1).fillna(0.0)
    level = (1.0 + mean_return).cumprod()
    level.name = 'index_level'
    return level


def add_excess_return(trades: pd.DataFrame, index_level: pd.Series) -> pd.DataFrame:
    """Add the return of each trade net of the equal weight index over the same dates.

    Returns the input pd.DataFrame plus the column TradeColumn.EXCESS_PCT.
    """
    entry_level = index_level.reindex(pd.to_datetime(trades[TradeColumn.ENTRY_DATE])).to_numpy()
    exit_level = index_level.reindex(pd.to_datetime(trades[TradeColumn.EXIT_DATE])).to_numpy()
    if np.isnan(entry_level).any() or np.isnan(exit_level).any():
        raise ValueError("a trade date is missing from the index; the index and the trades disagree on dates")
    market = 100.0 * (exit_level / entry_level - 1.0)
    frame = trades.copy()
    frame[TradeColumn.EXCESS_PCT] = frame[TradeColumn.RETURN_PCT] - market
    return frame


def find_signals(indicators: pd.DataFrame, params: RuleParams) -> np.ndarray:
    """Return the integer positions of the breakout days of one ticker."""
    close = indicators['close']
    band_high = indicators['band_high']
    converged = indicators['spread'] <= params.conv_max
    broke_out = (close > band_high) & (close.shift(1) <= band_high.shift(1))
    heavy = indicators['volume'] >= params.vol_mult * indicators['vol_mean']
    wide = indicators['body'] >= params.body_min
    hit = converged & broke_out & heavy & wide
    return np.flatnonzero(hit.to_numpy(dtype=bool))


def _close_position(indicators: pd.DataFrame, entry_pos: int, params: RuleParams) -> tuple:
    """Walk one position forward and return (exit_pos, exit_price, reason).

    The stop is the bundle floor of the breakout day; it is checked on closes and filled at the
    next open, which is the earliest price a close based rule can actually trade at.
    """
    close = indicators['close'].to_numpy()
    open_ = indicators['open'].to_numpy()
    stop_level = indicators['band_low'].to_numpy()[entry_pos - 1]
    last_pos = len(close) - 1

    for pos in range(entry_pos, min(entry_pos + params.hold_days, last_pos) + 1):
        if close[pos] < stop_level:
            if pos == last_pos:
                return pos, close[pos], ExitReason.DATA_END
            return pos + 1, open_[pos + 1], ExitReason.STOP

    limit_pos = entry_pos + params.hold_days
    if limit_pos >= last_pos:
        return last_pos, close[last_pos], ExitReason.DATA_END
    return limit_pos + 1, open_[limit_pos + 1], ExitReason.HOLD_LIMIT


def _walk_entries(indicators: pd.DataFrame, entry_positions: np.ndarray, params: RuleParams,
                  ticker: str, arm: Arm) -> list:
    """Turn entry positions into non overlapping trades of one ticker."""
    dates = indicators['date'].to_numpy()
    open_ = indicators['open'].to_numpy()
    trades: list = []
    busy_until = -1

    for pos in entry_positions:
        entry_pos = pos + 1                          # the signal is only tradable at the next open
        if entry_pos <= busy_until or entry_pos >= len(open_):
            continue
        exit_pos, exit_price, reason = _close_position(indicators=indicators, entry_pos=entry_pos, params=params)
        entry_price = open_[entry_pos]
        trades.append({
            TradeColumn.TICKER: ticker,
            TradeColumn.ENTRY_DATE: dates[entry_pos],
            TradeColumn.EXIT_DATE: dates[exit_pos],
            TradeColumn.ENTRY_PRICE: entry_price,
            TradeColumn.EXIT_PRICE: exit_price,
            TradeColumn.HOLD_DAYS: int(exit_pos - entry_pos),
            TradeColumn.RETURN_PCT: 100.0 * (exit_price / entry_price - 1.0),
            TradeColumn.EXIT_REASON: str(reason),
            TradeColumn.ARM: str(arm),
        })
        busy_until = exit_pos

    return trades


def run_trades(prices: pd.DataFrame, params: RuleParams, seed: int = 20260910,
               progress: bool = True) -> pd.DataFrame:
    """Run the signal arm and the random control arm over every ticker.

    The control draws, per ticker, CONTROL_DRAWS_PER_SIGNAL entry days per signal, uniformly from
    the days where the bundle is defined, and closes them with the identical exit rule.

    Returns a pd.DataFrame with a RangeIndex and the columns of TradeColumn.
    """
    rng = np.random.default_rng(seed)
    warmup = max(MA_WINDOWS)
    groups = prices.groupby('ticker', sort=True)
    bar = tqdm(groups, ncols=100, unit='ticker', disable=not progress)
    trades: list = []

    for ticker, frame in bar:
        bar.set_description(f"Scanning {ticker}")
        if len(frame) <= warmup + 2:
            continue
        indicators = build_indicators(prices=frame)
        signal_positions = find_signals(indicators=indicators, params=params)
        trades.extend(_walk_entries(indicators=indicators, entry_positions=signal_positions,
                                    params=params, ticker=ticker, arm=Arm.SIGNAL))
        if len(signal_positions) == 0:
            continue
        pool = np.arange(warmup, len(indicators) - 1)
        draws = min(CONTROL_DRAWS_PER_SIGNAL * len(signal_positions), len(pool))
        drawn = np.sort(rng.choice(pool, size=draws, replace=False))
        trades.extend(_walk_entries(indicators=indicators, entry_positions=drawn,
                                    params=params, ticker=ticker, arm=Arm.RANDOM))

    if not trades:
        raise ValueError(f"no trade was produced by {params}; loosen the thresholds or check the data")
    return pd.DataFrame(trades)


def summarize(trades: pd.DataFrame) -> dict:
    """Reduce one arm of trades to the reported statistics."""
    returns = trades[TradeColumn.RETURN_PCT].to_numpy(dtype=float)
    wins = returns[returns > 0.0]
    losses = returns[returns <= 0.0]
    hold = trades[TradeColumn.HOLD_DAYS].to_numpy(dtype=float)
    stderr = returns.std(ddof=1) / np.sqrt(len(returns)) if len(returns) > 1 else np.nan
    profit_factor = wins.sum() / abs(losses.sum()) if losses.sum() != 0.0 else np.inf
    return {
        'trades': int(len(returns)),
        'win_rate_pct': round(100.0 * len(wins) / len(returns), 2),
        'mean_return_pct': round(float(returns.mean()), 3),
        'median_return_pct': round(float(np.median(returns)), 3),
        'std_return_pct': round(float(returns.std(ddof=1)), 3),
        'mean_win_pct': round(float(wins.mean()), 3) if len(wins) else np.nan,
        'mean_loss_pct': round(float(losses.mean()), 3) if len(losses) else np.nan,
        'profit_factor': round(float(profit_factor), 3),
        'mean_hold_days': round(float(hold.mean()), 1),
        'mean_excess_pct': round(float(trades[TradeColumn.EXCESS_PCT].mean()), 3),
        't_stat': round(float(returns.mean() / stderr), 2) if np.isfinite(stderr) else np.nan,
        'stop_exit_pct': round(100.0 * float((trades[TradeColumn.EXIT_REASON] == ExitReason.STOP).mean()), 1),
    }


def position_returns(prices: pd.DataFrame, trades: pd.DataFrame) -> tuple:
    """Spread the trades over the calendar and add up what the open positions earn each day.

    A position earns close/open on its entry day, close on close while it is held, and the recorded
    exit price against the previous close on its exit day.

    Returns (calendar, summed daily return of the open positions, number of open positions), the
    last two as float arrays aligned with the calendar.
    """
    calendar = np.sort(prices['date'].unique())
    series = {ticker: (frame['date'].to_numpy(), frame['open'].to_numpy(), frame['close'].to_numpy())
              for ticker, frame in prices.groupby('ticker', sort=False)}
    total = np.zeros(len(calendar))
    count = np.zeros(len(calendar))

    for row in trades.itertuples(index=False):
        ticker = getattr(row, TradeColumn.TICKER)
        if ticker not in series:
            raise ValueError(f"trade on {ticker} has no price series; the trades and the prices disagree")
        dates, open_, close = series[ticker]
        entry_pos = int(np.searchsorted(dates, np.datetime64(getattr(row, TradeColumn.ENTRY_DATE))))
        exit_pos = int(np.searchsorted(dates, np.datetime64(getattr(row, TradeColumn.EXIT_DATE))))
        exit_price = float(getattr(row, TradeColumn.EXIT_PRICE))

        returns = np.empty(exit_pos - entry_pos + 1)
        if exit_pos == entry_pos:
            returns[0] = exit_price / open_[entry_pos] - 1.0
        else:
            returns[0] = close[entry_pos] / open_[entry_pos] - 1.0
            held = np.arange(entry_pos + 1, exit_pos)
            returns[1:-1] = close[held] / close[held - 1] - 1.0
            returns[-1] = exit_price / close[exit_pos - 1] - 1.0

        slots = np.searchsorted(calendar, dates[entry_pos:exit_pos + 1])
        total[slots] += returns
        count[slots] += 1.0

    return calendar, total, count


def equity_curve(prices: pd.DataFrame, trades: pd.DataFrame) -> pd.DataFrame:
    """Turn one arm of trades into the daily curve of a portfolio that splits capital evenly.

    On a given day the capital is spread over the positions open that day and sits in cash, earning
    nothing, on the days with no position.

    Returns a pd.DataFrame indexed by 'date' with the columns
    ['daily_return', 'open_positions', 'equity'], where equity starts at 1.0 before the first day.
    """
    calendar, total, count = position_returns(prices=prices, trades=trades)
    daily = np.where(count > 0.0, total / np.where(count > 0.0, count, 1.0), 0.0)
    return pd.DataFrame({'daily_return': daily, 'open_positions': count.astype(int),
                         'equity': (1.0 + daily).cumprod()}, index=pd.Index(calendar, name='date'))


def load_index_level(csv_path: pathlib.Path, calendar: np.ndarray, url: str = INDEX_URL) -> pd.Series:
    """Read a daily index csv and return its close on exactly the given calendar.

    A calendar day the file does not cover is an error rather than a gap, because a curve drawn
    over a partial index would still look like a full comparison.

    Returns a pd.Series named 'index_level' indexed by 'date'.
    """
    if not csv_path.exists():
        csv_path.parent.mkdir(parents=True, exist_ok=True)
        print(f"downloading {url} -> {csv_path}", flush=True)
        urllib.request.urlretrieve(url, csv_path)

    frame = pd.read_csv(csv_path, parse_dates=['date'])
    missing = {'date', 'close'} - set(frame.columns)
    if missing:
        raise ValueError(f"{csv_path} lacks columns {sorted(missing)}; got {sorted(frame.columns)}")

    level = frame.set_index('date')['close'].sort_index().reindex(pd.Index(calendar, name='date'))
    absent = level[level.isna()]
    if not absent.empty:
        raise ValueError(f"{csv_path} misses {len(absent)} of the {len(calendar)} sample days, "
                         f"first {absent.index[0].date()}; the index does not cover the sample")
    level.name = 'index_level'
    return level


def index_curve(index_level: pd.Series) -> pd.DataFrame:
    """Cast the equal weight index into the same shape as an equity curve, so both are read alike.

    Returns a pd.DataFrame indexed by 'date' with the columns
    ['daily_return', 'open_positions', 'equity'].
    """
    daily = index_level.pct_change().fillna(0.0)
    return pd.DataFrame({'daily_return': daily.to_numpy(),
                         'open_positions': np.ones(len(daily), dtype=int),
                         'equity': (1.0 + daily).cumprod().to_numpy()},
                        index=pd.Index(index_level.index, name='date'))


def portfolio_stats(curve: pd.DataFrame) -> dict:
    """Reduce one equity curve to its cumulative return, annualized return and drawdown."""
    equity = curve['equity'].to_numpy()
    years = len(equity) / TRADING_DAYS_PER_YEAR
    drawdown = equity / np.maximum.accumulate(equity) - 1.0
    return {
        'days': int(len(equity)),
        'years': round(float(years), 2),
        'total_return_pct': round(100.0 * float(equity[-1] - 1.0), 2),
        'cagr_pct': round(100.0 * float(equity[-1] ** (1.0 / years) - 1.0), 2),
        'max_drawdown_pct': round(100.0 * float(drawdown.min()), 2),
        'time_in_market_pct': round(100.0 * float((curve['open_positions'] > 0).mean()), 1),
        'mean_open_positions': round(float(curve['open_positions'].mean()), 1),
    }


def buy_and_hold(prices: pd.DataFrame) -> dict:
    """Return the equal weight buy and hold statistics of the universe over the whole sample."""
    first = prices.groupby('ticker')['close'].first()
    last = prices.groupby('ticker')['close'].last()
    span = prices.groupby('ticker')['date'].size()
    total = 100.0 * (last / first - 1.0)
    years = float(span.mean()) / TRADING_DAYS_PER_YEAR
    return {
        'tickers': int(len(total)),
        'mean_total_return_pct': round(float(total.mean()), 2),
        'median_total_return_pct': round(float(total.median()), 2),
        'years': round(years, 2),
        'mean_return_per_60_days_pct': round(float(total.mean()) * 60.0 / (years * TRADING_DAYS_PER_YEAR), 3),
    }


def run_grid(prices: pd.DataFrame, conv_values: list, vol_values: list, hold_values: list,
             body_min: float, seed: int) -> pd.DataFrame:
    """Run the rule over a parameter grid.

    Returns a pd.DataFrame with a RangeIndex and the columns
    ['conv_max', 'vol_mult', 'hold_days', 'arm'] plus every key of summarize().
    """
    rows: list = []
    index_level = build_market_index(prices=prices)
    combos = [(c, v, h) for c in conv_values for v in vol_values for h in hold_values]
    bar = tqdm(combos, ncols=100, unit='combo')

    for conv_max, vol_mult, hold_days in bar:
        bar.set_description(f"conv={conv_max} vol={vol_mult} hold={hold_days}")
        params = RuleParams(conv_max=conv_max, vol_mult=vol_mult, body_min=body_min, hold_days=hold_days)
        trades = run_trades(prices=prices, params=params, seed=seed, progress=False)
        trades = add_excess_return(trades=trades, index_level=index_level)
        for arm in (Arm.SIGNAL, Arm.RANDOM):
            arm_trades = trades[trades[TradeColumn.ARM] == arm]
            if arm_trades.empty:
                continue
            rows.append({'conv_max': conv_max, 'vol_mult': vol_mult, 'hold_days': hold_days,
                         'arm': str(arm), **summarize(trades=arm_trades)})

    return pd.DataFrame(rows)


def plot_results(trades: pd.DataFrame, grid: pd.DataFrame, base_hold: int, fig_path: pathlib.Path) -> None:
    """Draw the trade return distribution and the two parameter sweeps into one three panel figure."""
    font_size = BASE_FONT_SIZE * FIGSIZE[0] / REFERENCE_WIDTH
    colors = list(matplotlib.colors.TABLEAU_COLORS.values())
    fig, axes = plt.subplots(nrows=1, ncols=3, figsize=FIGSIZE)

    bins = np.linspace(-40.0, 40.0, 61)
    for index, arm in enumerate((Arm.SIGNAL, Arm.RANDOM)):
        values = trades.loc[trades[TradeColumn.ARM] == arm, TradeColumn.RETURN_PCT].to_numpy(dtype=float)
        axes[0].hist(np.clip(values, bins[0], bins[-1]), bins=bins, density=True, alpha=0.55,
                     color=colors[index], label=f"{arm} (n={len(values)}, mean={values.mean():.2f}%)")
    axes[0].axvline(0.0, color='black', linewidth=0.8)
    axes[0].set_xlabel('Trade return (%)', fontsize=font_size)
    axes[0].set_ylabel('Density', fontsize=font_size)
    axes[0].legend(fontsize=font_size, loc='upper left')

    sweep = grid[(grid['arm'] == str(Arm.SIGNAL)) & (grid['hold_days'] == base_hold)]
    control = grid[(grid['arm'] == str(Arm.RANDOM)) & (grid['hold_days'] == base_hold)]
    panels = ((axes[1], 'mean_return_pct', 'Mean return (%)'),
              (axes[2], 'mean_excess_pct', 'Mean excess return (%)'))

    for axis, column, ylabel in panels:
        for index, vol_mult in enumerate(sorted(sweep['vol_mult'].unique())):
            line = sweep[sweep['vol_mult'] == vol_mult].sort_values('conv_max')
            axis.plot(100.0 * line['conv_max'], line[column], marker='o', color=colors[index],
                      label=f"volume >= {vol_mult}x")
        axis.axhline(control[column].mean(), color='black', linestyle='--', linewidth=0.9,
                     label=f"random entry ({control[column].mean():.2f}%)")
        axis.set_xlabel('Convergence threshold (%)', fontsize=font_size)
        axis.set_ylabel(f"{ylabel}, hold {base_hold} days", fontsize=font_size)
        axis.legend(fontsize=font_size, loc='best')

    for axis in axes:
        axis.tick_params(labelsize=font_size)
        axis.grid(alpha=0.25)

    fig.subplots_adjust(left=0.06, right=0.99, bottom=0.26, top=0.96, wspace=0.30)
    for axis, label in zip(axes, ('(a)', '(b)', '(c)')):
        box = axis.get_position()
        fig.text(box.x0 + box.width / 2.0, 0.05, label, ha='center', va='center', fontsize=font_size + 1)

    fig_path.parent.mkdir(parents=True, exist_ok=True)
    fig.savefig(fig_path, dpi=300)
    plt.close(fig)


def plot_equity(curves: dict, fig_path: pathlib.Path) -> None:
    """Draw the equity of every named curve on one axis, each labelled with its annualized return."""
    font_size = BASE_FONT_SIZE * EQUITY_FIGSIZE[0] / REFERENCE_WIDTH
    colors = list(matplotlib.colors.TABLEAU_COLORS.values())
    fig, axis = plt.subplots(nrows=1, ncols=1, figsize=EQUITY_FIGSIZE)

    for index, (name, curve) in enumerate(curves.items()):
        stats = portfolio_stats(curve=curve)
        axis.plot(curve.index, curve['equity'], color=colors[index],
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


def parse_args() -> argparse.Namespace:
    """Parse the command line options."""
    parser = argparse.ArgumentParser(
        prog=pathlib.Path(__file__).name,
        description=f"{pathlib.Path(__file__).name} {__version__}\n"
                    f"Backtest the klomagi moving average convergence breakout rule.",
        formatter_class=argparse.RawTextHelpFormatter)
    parser.add_argument('-v', '--version', action='version', version=f"{pathlib.Path(__file__).name} {__version__}")
    parser.add_argument('--data-csv', type=str, required=True,
                        help="daily OHLCV csv; downloaded from the public dataset when absent")
    parser.add_argument('--index-csv', type=str, required=True,
                        help="daily S&P 500 index csv; downloaded from the public dataset when absent")
    parser.add_argument('--output-folder', type=str, required=True,
                        help="root folder of every output file")
    parser.add_argument('--conv-max', type=float, default=0.03,
                        help="base convergence threshold of the moving average bundle")
    parser.add_argument('--vol-mult', type=float, default=2.0,
                        help="base volume multiple of the trailing mean volume")
    parser.add_argument('--body-min', type=float, default=0.03,
                        help="base minimum body of the breakout candle")
    parser.add_argument('--hold-days', type=int, default=60,
                        help="base holding limit in trading days")
    parser.add_argument('--grid', choices=['true', 'false'], default='true',
                        help="also run the parameter sweep")
    parser.add_argument('--seed', type=int, default=20260910,
                        help="seed of the random entry control")

    if len(sys.argv) == 1:
        parser.print_help()
        sys.exit(0)

    args = parser.parse_args()
    args.data_csv = pathlib.Path(args.data_csv)
    args.index_csv = pathlib.Path(args.index_csv)
    args.output_folder = pathlib.Path(args.output_folder)
    args.grid = args.grid == 'true'
    if args.hold_days < 1:
        parser.error(f"--hold-days must be positive; got {args.hold_days}")
    if not 0.0 < args.conv_max < 1.0:
        parser.error(f"--conv-max must lie in (0, 1); got {args.conv_max}")
    return args


if __name__ == '__main__':
    cli = parse_args()
    cli.output_folder.mkdir(parents=True, exist_ok=True)

    price_table = load_prices(csv_path=cli.data_csv)
    print(f"loaded {len(price_table)} rows, {price_table['ticker'].nunique()} tickers, "
          f"{price_table['date'].min().date()} .. {price_table['date'].max().date()}", flush=True)

    base = RuleParams(conv_max=cli.conv_max, vol_mult=cli.vol_mult,
                      body_min=cli.body_min, hold_days=cli.hold_days)
    market = build_market_index(prices=price_table)
    base_trades = run_trades(prices=price_table, params=base, seed=cli.seed)
    base_trades = add_excess_return(trades=base_trades, index_level=market)
    base_trades.to_csv(cli.output_folder / 'trades.csv', index=False)

    curves: dict = {str(arm): equity_curve(prices=price_table,
                                           trades=base_trades[base_trades[TradeColumn.ARM] == arm])
                    for arm in (Arm.SIGNAL, Arm.RANDOM)}
    curves['equal weight index'] = index_curve(index_level=market)
    curves['S&P 500 index'] = index_curve(
        index_level=load_index_level(csv_path=cli.index_csv, calendar=np.sort(price_table['date'].unique())))
    pd.concat({name: curve for name, curve in curves.items()}, axis=1).to_csv(cli.output_folder / 'equity.csv')
    plot_equity(curves=curves, fig_path=cli.output_folder / 'fig2.png')

    report: dict = {
        'data_url': DATA_URL,
        'index_url': INDEX_URL,
        'sample': {
            'rows': int(len(price_table)),
            'tickers': int(price_table['ticker'].nunique()),
            'first_date': str(price_table['date'].min().date()),
            'last_date': str(price_table['date'].max().date()),
        },
        'params': asdict(base),
        'buy_and_hold': buy_and_hold(prices=price_table),
        'arms': {str(arm): summarize(trades=base_trades[base_trades[TradeColumn.ARM] == arm])
                 for arm in (Arm.SIGNAL, Arm.RANDOM)},
        'portfolio': {name: portfolio_stats(curve=curve) for name, curve in curves.items()},
    }

    if cli.grid:
        grid_table = run_grid(prices=price_table, conv_values=[0.02, 0.03, 0.05],
                              vol_values=[1.5, 2.0, 3.0], hold_values=[20, cli.hold_days],
                              body_min=cli.body_min, seed=cli.seed)
        grid_table.to_csv(cli.output_folder / 'grid.csv', index=False)
        plot_results(trades=base_trades, grid=grid_table, base_hold=cli.hold_days,
                     fig_path=cli.output_folder / 'fig1.png')

    (cli.output_folder / 'summary.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps(report, indent=2), flush=True)
