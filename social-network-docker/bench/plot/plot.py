"""Графики нагрузочных замеров GET /user/search из JSON-итогов k6.

Запуск: python plot.py <каталог отчёта>. Читает <каталог>/results/<phase>/<app>-c<N>.json,
пишет в <каталог>: latency-<phase>.png, throughput-<phase>.png, results-<phase>.md
и, если есть обе фазы, before-vs-after.png.
"""

import json
import re
import sys
from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt  # noqa: E402

APPS = {"native": "#2a78d6", "symfony": "#eb6834"}  # палитра проверена на различимость, в т.ч. при дальтонизме
PHASES = {"before": "до индекса", "after": "после индекса"}
PERCENTILES = [("med", "p50"), ("p(95)", "p95"), ("p(99)", "p99")]
TEXT, MUTED, GRID, SURFACE = "#0b0b0b", "#52514e", "#e4e3df", "#fcfcfb"

plt.rcParams.update({
    "figure.facecolor": SURFACE,
    "axes.facecolor": SURFACE,
    "axes.edgecolor": GRID,
    "axes.labelcolor": MUTED,
    "axes.titlecolor": TEXT,
    "xtick.color": MUTED,
    "ytick.color": MUTED,
    "axes.grid": True,
    "grid.color": GRID,
    "grid.linewidth": 0.8,
    "axes.spines.top": False,
    "axes.spines.right": False,
    "font.size": 10,
})


def load(results_dir: Path) -> dict:
    """{phase: {app: {vus: metrics}}}"""
    data: dict = {}
    for path in sorted(results_dir.glob("*/*-c*.json")):
        match = re.fullmatch(r"(\w+)-c(\d+)\.json", path.name)
        if not match:
            continue
        summary = json.loads(path.read_text())
        metrics = summary["metrics"]
        seconds = summary["state"]["testRunDurationMs"] / 1000
        requests = metrics["http_reqs"]["values"]["count"]
        failed_rate = metrics["http_req_failed"]["values"]["rate"]
        data.setdefault(path.parent.name, {}).setdefault(match[1], {})[int(match[2])] = {
            "rps": requests * (1 - failed_rate) / seconds,
            "requests": requests,
            "failed": failed_rate,
            **{label: metrics["http_req_duration"]["values"][key] for key, label in PERCENTILES},
            "max": metrics["http_req_duration"]["values"]["max"],
        }
    return data


def concurrency_axis(axis, levels) -> None:
    axis.set_xscale("log")
    axis.set_xticks(levels, [str(level) for level in levels])
    axis.minorticks_off()
    axis.set_xlabel("одновременных запросов")


def plot_latency(phase: str, apps: dict, output: Path) -> None:
    levels = sorted({vus for points in apps.values() for vus in points})
    figure, axes = plt.subplots(1, len(PERCENTILES), figsize=(12, 4), sharey=True)
    for axis, (_, label) in zip(axes, PERCENTILES):
        for app, color in APPS.items():
            points = apps.get(app, {})
            xs = sorted(points)
            axis.plot(xs, [points[x][label] for x in xs], color=color, linewidth=2, marker="o", markersize=6, label=app)
        concurrency_axis(axis, levels)
        axis.set_yscale("log")
        axis.set_title(label)
    axes[0].set_ylabel("задержка, мс (лог. шкала)")
    axes[0].legend(frameon=False)
    figure.suptitle(f"GET /user/search: задержка {PHASES[phase]}", color=TEXT)
    figure.tight_layout()
    figure.savefig(output, dpi=150)
    plt.close(figure)


def plot_throughput(phase: str, apps: dict, output: Path) -> None:
    levels = sorted({vus for points in apps.values() for vus in points})
    width = 0.38
    figure, axis = plt.subplots(figsize=(8, 4.5))
    for index, (app, color) in enumerate(APPS.items()):
        points = apps.get(app, {})
        positions = [level_index + (index - 0.5) * width for level_index in range(len(levels))]
        values = [points[level]["rps"] if level in points else 0 for level in levels]
        bars = axis.bar(positions, values, width=width - 0.02, color=color, label=app)
        for bar, level in zip(bars, levels):
            if level not in points:
                continue
            caption = f"{points[level]['rps']:.0f}"
            if points[level]["failed"] > 0:
                caption += f"\nошибок {points[level]['failed'] * 100:.1f}%"
            axis.annotate(caption, (bar.get_x() + bar.get_width() / 2, bar.get_height()),
                          xytext=(0, 3), textcoords="offset points", ha="center", va="bottom", color=TEXT, fontsize=8)
    axis.set_xticks(range(len(levels)), [str(level) for level in levels])
    axis.set_xlabel("одновременных запросов")
    axis.set_ylabel("успешных запросов в секунду")
    axis.grid(axis="x", visible=False)
    axis.set_axisbelow(True)
    axis.margins(y=0.15)
    axis.legend(frameon=False)
    axis.set_title(f"GET /user/search: пропускная способность {PHASES[phase]}")
    figure.tight_layout()
    figure.savefig(output, dpi=150)
    plt.close(figure)


def plot_comparison(data: dict, output: Path) -> None:
    levels = sorted({vus for apps in data.values() for points in apps.values() for vus in points})
    figure, axes = plt.subplots(1, 2, figsize=(12, 4.5))
    for axis, (metric, title, ylabel) in zip(axes, [
        ("rps", "Пропускная способность", "успешных запросов в секунду (лог. шкала)"),
        ("p95", "Задержка p95", "мс (лог. шкала)"),
    ]):
        for app, color in APPS.items():
            for phase, style in [("before", "--"), ("after", "-")]:
                points = data.get(phase, {}).get(app, {})
                xs = sorted(points)
                if xs:
                    axis.plot(xs, [points[x][metric] for x in xs], color=color, linestyle=style, linewidth=2,
                              marker="o", markersize=6, label=f"{app}, {PHASES[phase]}")
        concurrency_axis(axis, levels)
        axis.set_yscale("log")
        axis.set_title(title)
        axis.set_ylabel(ylabel)
    axes[0].legend(frameon=False)
    figure.suptitle("GET /user/search: до и после индекса", color=TEXT)
    figure.tight_layout()
    figure.savefig(output, dpi=150)
    plt.close(figure)


def write_table(phase: str, apps: dict, output: Path) -> None:
    lines = [
        f"| Приложение | Конкурентность | Запросов | Успешных RPS | Ошибок | p50, мс | p95, мс | p99, мс | max, мс |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for app in APPS:
        for vus, point in sorted(apps.get(app, {}).items()):
            lines.append(
                f"| {app} | {vus} | {point['requests']} | {point['rps']:.1f} | {point['failed'] * 100:.2f}% "
                f"| {point['p50']:.1f} | {point['p95']:.1f} | {point['p99']:.1f} | {point['max']:.1f} |"
            )
    output.write_text("\n".join(lines) + "\n")


def main() -> None:
    report_dir = Path(sys.argv[1])
    data = load(report_dir / "results")
    if not data:
        sys.exit(f"нет итогов k6 в {report_dir / 'results'}")
    for phase, apps in data.items():
        if phase not in PHASES:
            continue
        plot_latency(phase, apps, report_dir / f"latency-{phase}.png")
        plot_throughput(phase, apps, report_dir / f"throughput-{phase}.png")
        write_table(phase, apps, report_dir / f"results-{phase}.md")
        print(f"{phase}: графики и таблица готовы")
    if all(phase in data for phase in PHASES):
        plot_comparison(data, report_dir / "before-vs-after.png")
        print("before-vs-after: готов")


if __name__ == "__main__":
    main()
