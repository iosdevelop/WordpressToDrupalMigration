#!/usr/bin/env python3
"""
IVMF Full Site Structure Crawler

Purpose:
    Crawl the public IVMF website, discover internal URLs, and export a complete
    current-site inventory for redirect mapping against the 2026 IA worksheet.

Usage:
    python crawl_ivmf.py https://ivmf.syracuse.edu
    python crawl_ivmf.py https://ivmf.syracuse.edu --output-dir ivmf-crawl
    python crawl_ivmf.py https://ivmf.syracuse.edu --delay 0.5 --max-pages 1000

Dependencies:
    pip install requests beautifulsoup4 pandas openpyxl lxml

Outputs:
    crawl-output/ivmf-url-site-structure-YYYYMMDD-HHMMSS.csv
    crawl-output/ivmf-url-site-structure-YYYYMMDD-HHMMSS.xlsx

Notes:
    - Traversal is restricted to the starting hostname.
    - File assets such as PDFs, images, documents, CSS, and JS are excluded
      from traversal but can be discovered if linked from HTML pages.
    - Query strings are removed by default to avoid duplicate crawl paths.
"""

from __future__ import annotations

import argparse
import csv
import sys
import time
from collections import deque
from dataclasses import asdict, dataclass
from datetime import datetime
from pathlib import Path
from typing import Iterable
from urllib.parse import parse_qsl, urlencode, urljoin, urlparse, urldefrag, urlunparse

import pandas as pd
import requests
from bs4 import BeautifulSoup


@dataclass(frozen=True)
class PageRecord:
    url: str
    page_title: str
    nav_label_candidate: str
    h1: str
    status_code: int
    content_type: str
    canonical_url: str
    parent_url: str
    crawl_depth: int
    path: str
    path_depth: int
    section_1: str
    section_2: str
    section_3: str
    discovered_from: str
    redirect_map_status: str
    proposed_new_page: str
    notes: str


@dataclass(frozen=True)
class AssetRecord:
    asset_url: str
    asset_type: str
    linked_from: str


class IVMFSiteCrawler:
    BLOCKED_EXTENSIONS = (
        ".jpg",
        ".jpeg",
        ".png",
        ".gif",
        ".webp",
        ".svg",
        ".ico",
        ".pdf",
        ".doc",
        ".docx",
        ".xls",
        ".xlsx",
        ".ppt",
        ".pptx",
        ".zip",
        ".mp4",
        ".mp3",
        ".mov",
        ".avi",
        ".css",
        ".js",
        ".xml",
        ".json",
        ".woff",
        ".woff2",
        ".ttf",
        ".eot",
    )

    BLOCKED_PATH_FRAGMENTS = (
        "/wp-admin/",
        "/wp-login.php",
        "/wp-json/",
        "/xmlrpc.php",
        "/feed/",
        "/comments/",
        "/trackback/",
        "/author/",
        "/tag/",
    )

    ALLOWED_QUERY_KEYS: set[str] = set()

    DEFAULT_HEADERS = {
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/125.0.0.0 Safari/537.36"
        ),
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.9",
        "Upgrade-Insecure-Requests": "1",
    }

    RETRY_403_HEADERS = {
        **DEFAULT_HEADERS,
        "Cache-Control": "no-cache",
        "Pragma": "no-cache",
    }

    def __init__(
        self,
        start_url: str,
        delay: float,
        timeout: int,
        max_pages: int | None,
        include_nofollow: bool,
    ) -> None:
        self.start_url = self.normalize_url(start_url)
        self.base_domain = urlparse(self.start_url).netloc.lower()
        self.delay = delay
        self.timeout = timeout
        self.max_pages = max_pages
        self.include_nofollow = include_nofollow

        self.session = requests.Session()
        self.session.headers.update(self.DEFAULT_HEADERS)

        self.visited: set[str] = set()
        self.queued: set[str] = set()
        self.records: list[PageRecord] = []
        self.assets: dict[str, AssetRecord] = {}

    def crawl(self) -> tuple[list[PageRecord], list[AssetRecord]]:
        queue: deque[tuple[str, str, int]] = deque()
        queue.append((self.start_url, "", 0))
        self.queued.add(self.start_url)

        while queue:
            if self.max_pages is not None and len(self.visited) >= self.max_pages:
                break

            url, discovered_from, depth = queue.popleft()

            if url in self.visited:
                continue

            print(f"[{len(self.visited) + 1}] {url}")
            self.visited.add(url)

            record, links = self.fetch_page(url, discovered_from, depth)

            if record is not None:
                self.records.append(record)

            for link in links:
                if link not in self.visited and link not in self.queued:
                    queue.append((link, url, depth + 1))
                    self.queued.add(link)

            time.sleep(self.delay)

        return self.records, sorted(self.assets.values(), key=lambda item: item.asset_url)

    def fetch_page(
        self,
        url: str,
        discovered_from: str,
        depth: int,
    ) -> tuple[PageRecord | None, list[str]]:
        try:
            response = self.session.get(
                url,
                timeout=self.timeout,
                allow_redirects=True,
            )
            if response.status_code == 403:
                response = self.session.get(
                    url,
                    headers=self.RETRY_403_HEADERS,
                    timeout=self.timeout,
                    allow_redirects=True,
                )
        except requests.RequestException as exc:
            print(f"    ERROR: {exc}", file=sys.stderr)
            return self.build_error_record(url, discovered_from, depth, str(exc)), []

        final_url = self.normalize_url(response.url)
        content_type = response.headers.get("content-type", "")

        if "text/html" not in content_type.lower():
            self.assets[final_url] = AssetRecord(
                asset_url=final_url,
                asset_type=content_type or "non-html",
                linked_from=discovered_from,
            )
            return None, []

        soup = BeautifulSoup(response.text, "lxml")

        page_title = self.extract_title(soup)
        h1 = self.extract_h1(soup)
        nav_label = h1 or self.clean_title_for_nav(page_title)
        canonical_url = self.extract_canonical(soup, final_url)

        path = urlparse(final_url).path or "/"
        sections = self.path_sections(final_url)

        record = PageRecord(
            url=final_url,
            page_title=page_title,
            nav_label_candidate=nav_label,
            h1=h1,
            status_code=response.status_code,
            content_type=content_type,
            canonical_url=canonical_url,
            parent_url=self.parent_url(final_url),
            crawl_depth=depth,
            path=path,
            path_depth=self.path_depth(final_url),
            section_1=sections[0],
            section_2=sections[1],
            section_3=sections[2],
            discovered_from=discovered_from,
            redirect_map_status="Unmapped",
            proposed_new_page="",
            notes="",
        )

        links = list(self.extract_links(soup, final_url))
        return record, links

    def extract_links(self, soup: BeautifulSoup, current_url: str) -> Iterable[str]:
        for anchor in soup.find_all("a", href=True):
            rel_values = anchor.get("rel") or []

            if not self.include_nofollow and "nofollow" in rel_values:
                continue

            href = anchor.get("href", "").strip()

            if not href:
                continue

            if href.startswith(("mailto:", "tel:", "javascript:", "#")):
                continue

            absolute_url = urljoin(current_url, href)
            normalized_url = self.normalize_url(absolute_url)

            if not self.is_internal_url(normalized_url):
                continue

            if self.is_asset_url(normalized_url):
                self.assets[normalized_url] = AssetRecord(
                    asset_url=normalized_url,
                    asset_type=self.extension_for_url(normalized_url),
                    linked_from=current_url,
                )
                continue

            if self.should_skip_url(normalized_url):
                continue

            yield normalized_url

    def build_error_record(
        self,
        url: str,
        discovered_from: str,
        depth: int,
        error: str,
    ) -> PageRecord:
        path = urlparse(url).path or "/"
        sections = self.path_sections(url)

        return PageRecord(
            url=url,
            page_title="",
            nav_label_candidate="",
            h1="",
            status_code=0,
            content_type="request-error",
            canonical_url="",
            parent_url=self.parent_url(url),
            crawl_depth=depth,
            path=path,
            path_depth=self.path_depth(url),
            section_1=sections[0],
            section_2=sections[1],
            section_3=sections[2],
            discovered_from=discovered_from,
            redirect_map_status="Error",
            proposed_new_page="",
            notes=error,
        )

    def is_internal_url(self, url: str) -> bool:
        parsed = urlparse(url)
        return parsed.netloc.lower() == self.base_domain

    def should_skip_url(self, url: str) -> bool:
        lowered_url = url.lower()
        return any(fragment in lowered_url for fragment in self.BLOCKED_PATH_FRAGMENTS)

    def is_asset_url(self, url: str) -> bool:
        path = urlparse(url).path.lower()
        return path.endswith(self.BLOCKED_EXTENSIONS)

    @classmethod
    def extension_for_url(cls, url: str) -> str:
        path = urlparse(url).path.lower()
        for extension in cls.BLOCKED_EXTENSIONS:
            if path.endswith(extension):
                return extension
        return "asset"

    @classmethod
    def normalize_url(cls, url: str) -> str:
        url, _fragment = urldefrag(url)
        parsed = urlparse(url)

        scheme = parsed.scheme or "https"
        netloc = parsed.netloc.lower()
        path = parsed.path or "/"

        if path != "/" and path.endswith("/"):
            path = path.rstrip("/")

        query_params = [
            (key, value)
            for key, value in parse_qsl(parsed.query, keep_blank_values=False)
            if key in cls.ALLOWED_QUERY_KEYS
        ]

        query = urlencode(query_params)

        return urlunparse((scheme, netloc, path, "", query, ""))

    @staticmethod
    def extract_title(soup: BeautifulSoup) -> str:
        if soup.title and soup.title.string:
            return " ".join(soup.title.string.split())
        return ""

    @staticmethod
    def extract_h1(soup: BeautifulSoup) -> str:
        h1 = soup.find("h1")
        if h1:
            return " ".join(h1.get_text(" ", strip=True).split())
        return ""

    @staticmethod
    def extract_canonical(soup: BeautifulSoup, current_url: str) -> str:
        canonical = soup.find("link", rel=lambda value: value and "canonical" in value)

        if canonical and canonical.get("href"):
            return urljoin(current_url, canonical["href"].strip())

        return ""

    @staticmethod
    def clean_title_for_nav(title: str) -> str:
        if not title:
            return ""

        separators = (" | ", " – ", " - ", " — ")

        for separator in separators:
            if separator in title:
                return title.split(separator)[0].strip()

        return title.strip()

    @staticmethod
    def parent_url(url: str) -> str:
        parsed = urlparse(url)
        path = parsed.path.rstrip("/")

        if not path or path == "/":
            return ""

        parent_path = "/".join(path.split("/")[:-1]) or "/"
        return urlunparse((parsed.scheme, parsed.netloc, parent_path, "", "", ""))

    @staticmethod
    def path_depth(url: str) -> int:
        path = urlparse(url).path.strip("/")
        if not path:
            return 0
        return len(path.split("/"))

    @staticmethod
    def path_sections(url: str) -> tuple[str, str, str]:
        parts = [part for part in urlparse(url).path.strip("/").split("/") if part]
        padded = parts + ["", "", ""]
        return padded[0], padded[1], padded[2]


def write_outputs(
    page_records: list[PageRecord],
    asset_records: list[AssetRecord],
    output_dir: Path,
) -> tuple[Path, Path]:
    output_dir.mkdir(parents=True, exist_ok=True)

    timestamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    csv_path = output_dir / f"ivmf-url-site-structure-{timestamp}.csv"
    xlsx_path = output_dir / f"ivmf-url-site-structure-{timestamp}.xlsx"

    page_rows = [asdict(record) for record in page_records]
    asset_rows = [asdict(record) for record in asset_records]

    page_columns = [
        "url",
        "page_title",
        "nav_label_candidate",
        "h1",
        "status_code",
        "content_type",
        "canonical_url",
        "parent_url",
        "crawl_depth",
        "path",
        "path_depth",
        "section_1",
        "section_2",
        "section_3",
        "discovered_from",
        "redirect_map_status",
        "proposed_new_page",
        "notes",
    ]

    with csv_path.open("w", newline="", encoding="utf-8") as file:
        writer = csv.DictWriter(file, fieldnames=page_columns)
        writer.writeheader()
        writer.writerows(page_rows)

    pages_df = pd.DataFrame(page_rows, columns=page_columns)

    if not pages_df.empty:
        pages_df = pages_df.sort_values(
            by=["path_depth", "path", "page_title"],
            ascending=[True, True, True],
        )

    assets_df = pd.DataFrame(
        asset_rows,
        columns=["asset_url", "asset_type", "linked_from"],
    )

    redirect_working_columns = [
        "url",
        "page_title",
        "nav_label_candidate",
        "path",
        "section_1",
        "section_2",
        "section_3",
        "proposed_new_page",
        "redirect_map_status",
        "notes",
    ]

    redirect_df = (
        pages_df[redirect_working_columns].copy()
        if not pages_df.empty
        else pd.DataFrame(columns=redirect_working_columns)
    )

    with pd.ExcelWriter(xlsx_path, engine="openpyxl") as writer:
        pages_df.to_excel(writer, sheet_name="URL Inventory", index=False)
        redirect_df.to_excel(writer, sheet_name="Redirect Working", index=False)
        assets_df.to_excel(writer, sheet_name="Linked Assets", index=False)

        workbook = writer.book

        for sheet_name in workbook.sheetnames:
            worksheet = workbook[sheet_name]
            worksheet.freeze_panes = "A2"
            worksheet.auto_filter.ref = worksheet.dimensions

            for column_cells in worksheet.columns:
                max_length = 0
                column_letter = column_cells[0].column_letter

                for cell in column_cells:
                    value = "" if cell.value is None else str(cell.value)
                    max_length = max(max_length, len(value))

                worksheet.column_dimensions[column_letter].width = min(max(max_length + 2, 12), 70)

    return csv_path, xlsx_path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Crawl IVMF public website and export a redirect-map-ready site structure."
    )

    parser.add_argument(
        "start_url",
        help="Starting URL. Example: https://ivmf.syracuse.edu",
    )

    parser.add_argument(
        "--delay",
        type=float,
        default=0.35,
        help="Delay between requests in seconds. Default: 0.35",
    )

    parser.add_argument(
        "--timeout",
        type=int,
        default=20,
        help="HTTP request timeout in seconds. Default: 20",
    )

    parser.add_argument(
        "--max-pages",
        type=int,
        default=None,
        help="Optional maximum number of HTML pages to crawl.",
    )

    parser.add_argument(
        "--output-dir",
        default="crawl-output",
        help="Directory for CSV/XLSX output. Default: crawl-output",
    )

    parser.add_argument(
        "--include-nofollow",
        action="store_true",
        help="Include links marked rel=nofollow.",
    )

    return parser.parse_args()


def main() -> int:
    args = parse_args()

    crawler = IVMFSiteCrawler(
        start_url=args.start_url,
        delay=args.delay,
        timeout=args.timeout,
        max_pages=args.max_pages,
        include_nofollow=args.include_nofollow,
    )

    page_records, asset_records = crawler.crawl()

    csv_path, xlsx_path = write_outputs(
        page_records=page_records,
        asset_records=asset_records,
        output_dir=Path(args.output_dir),
    )

    print()
    print("Crawl complete.")
    print(f"HTML pages:    {len(page_records)}")
    print(f"Linked assets: {len(asset_records)}")
    print(f"CSV:           {csv_path}")
    print(f"XLSX:          {xlsx_path}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
