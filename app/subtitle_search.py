#!/usr/bin/env python3
"""
SubSyncarr Subtitle Search & Download Engine
Searches OpenSubtitles.com, SubDL, and Podnapisi for subtitles.
Called by PHP via CLI: python3 subtitle_search.py <action> <json_args>
"""

import json
import os
import struct
import sys
import hashlib
import time
from pathlib import Path

try:
    import requests
except ImportError:
    print(json.dumps({"error": "requests library not installed"}))
    sys.exit(1)


# ── OpenSubtitles Hash (for matching) ───────────────────────────────────
def compute_os_hash(filepath):
    """Compute OpenSubtitles hash for a video file."""
    try:
        filesize = os.path.getsize(filepath)
        if filesize < 65536 * 2:
            return None, filesize

        hash_val = filesize
        with open(filepath, "rb") as f:
            for _ in range(65536 // 8):
                buf = f.read(8)
                (val,) = struct.unpack("<q", buf)
                hash_val += val
                hash_val &= 0xFFFFFFFFFFFFFFFF

            f.seek(max(0, filesize - 65536))
            for _ in range(65536 // 8):
                buf = f.read(8)
                (val,) = struct.unpack("<q", buf)
                hash_val += val
                hash_val &= 0xFFFFFFFFFFFFFFFF

        return "%016x" % hash_val, filesize
    except Exception:
        return None, 0


# ── Provider: OpenSubtitles.com ─────────────────────────────────────────
class OpenSubtitlesProvider:
    BASE_URL = "https://api.opensubtitles.com/api/v1"

    def __init__(self, api_key, username="", password=""):
        self.api_key = api_key
        self.username = username
        self.password = password
        self.token = None
        self.headers = {
            "Api-Key": api_key,
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "SubSyncarr v2.0",
        }

    def login(self):
        """Login to get JWT token (needed for downloads)."""
        if not self.username or not self.password:
            return False
        try:
            resp = requests.post(
                f"{self.BASE_URL}/login",
                headers=self.headers,
                json={"username": self.username, "password": self.password},
                timeout=10,
            )
            if resp.status_code == 200:
                data = resp.json()
                self.token = data.get("token")
                return True
        except Exception:
            pass
        return False

    def search(self, title, year=None, imdb_id=None, language="en",
               video_path=None, season=None, episode=None):
        """Search for subtitles."""
        results = []

        # Try hash-based search first (most accurate)
        if video_path and os.path.exists(video_path):
            file_hash, filesize = compute_os_hash(video_path)
            if file_hash:
                hash_results = self._search_by_hash(file_hash, filesize, language)
                results.extend(hash_results)

        # Also search by title/IMDB
        query_results = self._search_by_query(
            title, year, imdb_id, language, season, episode
        )
        results.extend(query_results)

        # Deduplicate by file_id
        seen = set()
        unique = []
        for r in results:
            fid = r.get("file_id")
            if fid and fid not in seen:
                seen.add(fid)
                unique.append(r)
        return unique

    def _search_by_hash(self, file_hash, filesize, language):
        params = {
            "moviehash": file_hash,
            "languages": language,
        }
        return self._do_search(params, match_type="hash")

    def _search_by_query(self, title, year, imdb_id, language,
                         season=None, episode=None):
        params = {"languages": language}
        if imdb_id:
            if season:
                params["parent_imdb_id"] = imdb_id.replace("tt", "")
                params["season_number"] = season
                if episode:
                    params["episode_number"] = episode
            else:
                params["imdb_id"] = imdb_id.replace("tt", "")
        else:
            params["query"] = title
            if season:
                # Searching a TV series — tell the API to look for episodes
                params["type"] = "episode"
                params["season_number"] = season
                if episode:
                    params["episode_number"] = episode
            else:
                params["type"] = "movie"
                if year:
                    params["year"] = year
        return self._do_search(params, match_type="query")

    def _do_search(self, params, match_type="query"):
        results = []
        try:
            resp = requests.get(
                f"{self.BASE_URL}/subtitles",
                headers=self.headers,
                params=params,
                timeout=15,
            )
            if resp.status_code != 200:
                return results

            data = resp.json()
            for item in data.get("data", [])[:50]:
                attrs = item.get("attributes", {})
                files = attrs.get("files", [])
                if not files:
                    continue

                feature = attrs.get("feature_details", {})
                best_file = files[0]

                # Build a clear display name
                feat_title = feature.get("title", "")
                parent_title = feature.get("parent_title", "")
                s_num = feature.get("season_number")
                e_num = feature.get("episode_number")
                display_name = best_file.get("file_name", "") or attrs.get("release", "")
                if parent_title and s_num and e_num:
                    ep_title = feat_title if feat_title else ""
                    display_name = f"S{str(s_num).zfill(2)}E{str(e_num).zfill(2)}" + (f" - {ep_title}" if ep_title else "") + f"  ({attrs.get('release', '')[:40]})"

                results.append({
                    "provider": "opensubtitles",
                    "provider_name": "OpenSubtitles.com",
                    "file_id": str(best_file.get("file_id", "")),
                    "filename": display_name,
                    "language": attrs.get("language", ""),
                    "release": attrs.get("release", ""),
                    "download_count": attrs.get("download_count", 0),
                    "hearing_impaired": attrs.get("hearing_impaired", False),
                    "fps": attrs.get("fps", 0),
                    "match_type": match_type,
                    "rating": attrs.get("ratings", 0),
                    "uploader": attrs.get("uploader", {}).get("name", ""),
                    "machine_translated": attrs.get("machine_translated", False),
                    # Structured metadata for accurate filtering
                    "feature_title": feature.get("title", ""),
                    "parent_title": feature.get("parent_title", ""),
                    "season_number": feature.get("season_number"),
                    "episode_number": feature.get("episode_number"),
                })
        except Exception as e:
            pass
        return results

    def download(self, file_id, output_path):
        """Download a subtitle file."""
        # Login if we haven't yet
        if not self.token:
            self.login()

        headers = dict(self.headers)
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        try:
            resp = requests.post(
                f"{self.BASE_URL}/download",
                headers=headers,
                json={"file_id": int(file_id)},
                timeout=15,
            )
            if resp.status_code != 200:
                return {"ok": False, "error": f"Download request failed: {resp.status_code}"}

            data = resp.json()
            link = data.get("link")
            if not link:
                return {"ok": False, "error": "No download link returned"}

            # Download the actual file
            file_resp = requests.get(link, timeout=30)
            if file_resp.status_code == 200:
                with open(output_path, "wb") as f:
                    f.write(file_resp.content)
                return {"ok": True, "path": output_path, "size": len(file_resp.content)}

            return {"ok": False, "error": f"File download failed: {file_resp.status_code}"}
        except Exception as e:
            return {"ok": False, "error": str(e)}


# ── Provider: SubDL ─────────────────────────────────────────────────────
class SubDLProvider:
    BASE_URL = "https://api.subdl.com/api/v1/subtitles"

    def __init__(self, api_key):
        self.api_key = api_key

    def search(self, title, year=None, imdb_id=None, language="en",
               season=None, episode=None, **kwargs):
        """Search SubDL for subtitles."""
        results = []
        params = {
            "api_key": self.api_key,
            "languages": language,
            "subs_per_page": 15,
            "type": "movie" if not season else "tv",
        }

        if imdb_id:
            params["imdb_id"] = imdb_id
        else:
            params["film_name"] = title
            if year:
                params["year"] = year

        if season:
            params["season_number"] = season
        if episode:
            params["episode_number"] = episode

        try:
            resp = requests.get(self.BASE_URL, params=params, timeout=15)
            if resp.status_code != 200:
                return results

            data = resp.json()
            if not data.get("status"):
                return results

            for item in data.get("subtitles", [])[:15]:
                results.append({
                    "provider": "subdl",
                    "provider_name": "SubDL",
                    "file_id": item.get("url", ""),
                    "filename": item.get("release_name", ""),
                    "language": item.get("language", ""),
                    "release": item.get("release_name", ""),
                    "download_count": 0,
                    "hearing_impaired": item.get("hi", False),
                    "fps": 0,
                    "match_type": "query",
                    "rating": 0,
                    "uploader": item.get("author", ""),
                    "machine_translated": False,
                })
        except Exception:
            pass
        return results

    def download(self, url, output_path):
        """Download a subtitle from SubDL."""
        try:
            if not url.startswith("http"):
                url = "https://dl.subdl.com" + url

            resp = requests.get(url, timeout=30)
            if resp.status_code != 200:
                return {"ok": False, "error": f"Download failed: {resp.status_code}"}

            # SubDL returns a zip file — extract the first .srt
            import zipfile
            import io

            with zipfile.ZipFile(io.BytesIO(resp.content)) as zf:
                sub_files = [
                    n for n in zf.namelist()
                    if n.lower().endswith(('.srt', '.ass', '.ssa', '.vtt'))
                ]
                if not sub_files:
                    return {"ok": False, "error": "No subtitle file found in archive"}

                content = zf.read(sub_files[0])
                with open(output_path, "wb") as f:
                    f.write(content)
                return {"ok": True, "path": output_path, "size": len(content),
                        "extracted": sub_files[0]}

        except Exception as e:
            return {"ok": False, "error": str(e)}


# ── Provider: Podnapisi ─────────────────────────────────────────────────
class PodnapisiProvider:
    BASE_URL = "https://www.podnapisi.net/subtitles/search/advanced"
    DOWNLOAD_URL = "https://www.podnapisi.net/subtitles"

    def search(self, title, year=None, language="en",
               season=None, episode=None, **kwargs):
        """Search Podnapisi for subtitles."""
        results = []

        # Map language codes to Podnapisi codes
        lang_map = {
            "en": "2", "es": "28", "fr": "8", "de": "5", "it": "9",
            "pt": "26", "ru": "27", "pl": "23", "nl": "13", "sv": "25",
            "da": "24", "fi": "31", "no": "22", "cs": "7", "hu": "18",
            "ro": "33", "tr": "30", "ar": "38", "he": "22", "zh": "17",
            "ja": "11", "ko": "4",
        }
        pod_lang = lang_map.get(language, "2")

        params = {
            "keywords": title,
            "language": pod_lang,
            "output_type": "json",
        }
        if year:
            params["year"] = year
        if season:
            params["seasons"] = season
        if episode:
            params["episodes"] = episode

        try:
            resp = requests.get(
                self.BASE_URL, params=params, timeout=15,
                headers={"Accept": "application/json",
                         "User-Agent": "SubSyncarr v2.0"},
            )
            if resp.status_code != 200:
                return results

            data = resp.json()
            for item in data.get("data", data.get("subtitles", []))[:15]:
                pid = item.get("id", "")
                results.append({
                    "provider": "podnapisi",
                    "provider_name": "Podnapisi",
                    "file_id": str(pid),
                    "filename": item.get("release", item.get("title", "")),
                    "language": language,
                    "release": item.get("release", ""),
                    "download_count": item.get("downloads", 0),
                    "hearing_impaired": item.get("hearing_impaired", False),
                    "fps": item.get("fps", 0),
                    "match_type": "query",
                    "rating": item.get("rating", 0),
                    "uploader": "",
                    "machine_translated": False,
                })
        except Exception:
            pass
        return results

    def download(self, file_id, output_path):
        """Download a subtitle from Podnapisi."""
        try:
            url = f"{self.DOWNLOAD_URL}/{file_id}/download"
            resp = requests.get(
                url, timeout=30,
                headers={"User-Agent": "SubSyncarr v2.0"},
                allow_redirects=True,
            )
            if resp.status_code != 200:
                return {"ok": False, "error": f"Download failed: {resp.status_code}"}

            # May be a zip or direct file
            content = resp.content
            if content[:2] == b'PK':  # ZIP file
                import zipfile
                import io
                with zipfile.ZipFile(io.BytesIO(content)) as zf:
                    sub_files = [
                        n for n in zf.namelist()
                        if n.lower().endswith(('.srt', '.ass', '.ssa', '.vtt'))
                    ]
                    if sub_files:
                        content = zf.read(sub_files[0])

            with open(output_path, "wb") as f:
                f.write(content)
            return {"ok": True, "path": output_path, "size": len(content)}

        except Exception as e:
            return {"ok": False, "error": str(e)}


# ── Provider: Addic7ed ───────────────────────────────────────────────────
class Addic7edProvider:
    BASE_URL = "https://www.addic7ed.com"

    def __init__(self, username="", password=""):
        self.username = username
        self.password = password
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
        })
        self.logged_in = False

    LANG_CODE_TO_ADDIC7ED = {
        "en": ["English"],
        "es": ["Spanish", "Spanish (Latin America)", "Spanish (Spain)"],
        "fr": ["French"],
        "de": ["German"],
        "it": ["Italian"],
        "pt": ["Portuguese", "Portuguese (Brazilian)"],
        "ru": ["Russian"],
        "nl": ["Dutch"],
        "pl": ["Polish"],
        "sv": ["Swedish"],
        "da": ["Danish"],
        "fi": ["Finnish"],
        "no": ["Norwegian"],
        "tr": ["Turkish"],
        "ar": ["Arabic"],
        "he": ["Hebrew"],
        "zh": ["Chinese"],
        "ja": ["Japanese"],
        "ko": ["Korean"],
        "cs": ["Czech"],
        "hu": ["Hungarian"],
        "ro": ["Romanian"],
    }

    ADDIC7ED_TO_LANG_CODE = {
        "english": "en",
        "spanish": "es",
        "spanish (latin america)": "es",
        "spanish (spain)": "es",
        "french": "fr",
        "german": "de",
        "italian": "it",
        "portuguese": "pt",
        "portuguese (brazilian)": "pt",
        "russian": "ru",
        "dutch": "nl",
        "polish": "pl",
        "swedish": "sv",
        "danish": "da",
        "finnish": "fi",
        "norwegian": "no",
        "turkish": "tr",
        "arabic": "ar",
        "hebrew": "he",
        "chinese": "zh",
        "japanese": "ja",
        "korean": "ko",
        "czech": "cs",
        "hungarian": "hu",
        "romanian": "ro",
    }

    def _debug(self, message):
        print(f"SubSyncarr Addic7ed: {message}", file=sys.stderr)

    def _login(self):
        if self.logged_in:
            return True
        if not self.username or not self.password:
            return False
        try:
            resp = self.session.post(
                f"{self.BASE_URL}/dologin.php",
                data={"username": self.username, "password": self.password, "remember": "1"},
                timeout=15,
                allow_redirects=True,
            )
            body = (resp.text or "").lower()
            # Addic7ed has changed login markup over time. This is intentionally conservative:
            # do not mark login as successful when the login form/error text is still present.
            if resp.status_code == 200 and "incorrect" not in body and "wrong" not in body:
                self.logged_in = True
                return True
            self._debug(f"login may have failed; HTTP {resp.status_code}")
        except Exception as e:
            self._debug(f"login error: {e}")
        return False

    def _wanted_addic7ed_languages(self, language):
        return self.LANG_CODE_TO_ADDIC7ED.get(language or "en", ["English"])

    def _language_code_from_text(self, text):
        lower = (text or "").lower()
        # Match longer names first so Spanish (Latin America) wins before Spanish.
        for name in sorted(self.ADDIC7ED_TO_LANG_CODE, key=len, reverse=True):
            if name in lower:
                return self.ADDIC7ED_TO_LANG_CODE[name]
        return ""

    def _parse_episode_page(self, soup, wanted_language="en", title="", season=None, episode=None):
        results = []
        wanted_names = [n.lower() for n in self._wanted_addic7ed_languages(wanted_language)]

        # Addic7ed episode pages store subtitle blocks in table.tabel95.
        sub_tables = soup.find_all(
            "table",
            {"width": "100%", "border": "0", "align": "center", "class": "tabel95"},
        )
        if not sub_tables:
            sub_tables = soup.select("table.tabel95")

        for sub_table in sub_tables:
            table_text = sub_table.get_text(" ", strip=True)

            version = ""
            title_cell = sub_table.find("td", {"colspan": "3", "align": "center", "class": "NewsTitle"})
            if title_cell:
                version = title_cell.get_text(" ", strip=True)
                # The site often shows strings like "Version FENIX, 0.00 MBs".
                if "Version " in version:
                    version = version.split("Version ", 1)[1]
                if "," in version:
                    version = version.split(",", 1)[0].strip()

            works_with_cell = sub_table.find("td", {"class": "newsDate", "colspan": "3"})
            works_with = works_with_cell.get_text(" ", strip=True) if works_with_cell else ""
            release = ", ".join([x for x in [version, works_with] if x]) or title or "Addic7ed subtitle"

            for lang_cell in sub_table.find_all("td", {"class": "language"}):
                lang_text = lang_cell.get_text(" ", strip=True)
                lang_text_lower = lang_text.lower()

                if wanted_names and not any(name in lang_text_lower for name in wanted_names):
                    continue

                lang_code = self._language_code_from_text(lang_text) or wanted_language
                if wanted_language and lang_code and lang_code != wanted_language:
                    continue

                download_cell = lang_cell.find_next("td", {"colspan": "3"})
                if not download_cell:
                    continue

                dl_link = download_cell.find(
                    "a",
                    href=lambda h: h and (h.startswith("/original") or h.startswith("/updated")),
                )
                if not dl_link:
                    # Some older pages/classes vary; fall back to any original/updated link in this table.
                    dl_link = sub_table.find(
                        "a",
                        href=lambda h: h and (h.startswith("/original") or h.startswith("/updated")),
                    )
                if not dl_link:
                    continue

                href = dl_link.get("href", "")
                hi = "hearing impaired" in table_text.lower()
                unfinished = "/jointranslation" in str(sub_table)
                if unfinished:
                    # Do not offer unfinished Addic7ed translations as downloadable results.
                    continue

                ep_label = ""
                if season and episode:
                    try:
                        ep_label = f"S{int(season):02d}E{int(episode):02d} - "
                    except Exception:
                        ep_label = f"S{season}E{episode} - "

                display_name = f"{title} {ep_label}{release}".strip()

                results.append({
                    "provider": "addic7ed",
                    "provider_name": "Addic7ed",
                    "file_id": href,
                    "filename": display_name,
                    "language": lang_code or wanted_language,
                    "release": f"{title} {release}".strip(),
                    "download_count": 0,
                    "hearing_impaired": hi,
                    "fps": 0,
                    "match_type": "query",
                    "rating": 0,
                    "uploader": "",
                    "machine_translated": False,
                })

        return results

    def search(self, title, year=None, language="en", season=None, episode=None, **kwargs):
        results = []
        try:
            from bs4 import BeautifulSoup
        except ImportError:
            self._debug("BeautifulSoup is not installed")
            return results

        # Addic7ed is TV-focused. Do not try movie searches.
        if not season or not episode:
            # Silent skip — movie searches are expected to bypass Addic7ed; debug noise
            # here was leaking into stderr and breaking the JSON parse in PHP.
            return results

        # Login is optional for browsing, but useful when Addic7ed requires it for downloads.
        self._login()

        try:
            search_query = f"{title} s{int(season):02d}e{int(episode):02d}"
        except Exception:
            search_query = f"{title} s{season}e{episode}"

        try:
            resp = self.session.get(
                f"{self.BASE_URL}/search.php",
                params={"search": search_query, "Submit": "Search"},
                timeout=20,
                allow_redirects=True,
            )
            if resp.status_code != 200:
                self._debug(f"search failed HTTP {resp.status_code}")
                return results

            soup = BeautifulSoup(resp.text, "html.parser")

            # Case 1: Addic7ed may redirect directly to the episode page.
            results = self._parse_episode_page(soup, language, title=title, season=season, episode=episode)
            if results:
                return results[:15]

            # Case 2: Search results page contains /serie/... links; follow likely episode links.
            episode_links = []
            for a in soup.find_all("a", href=True):
                href = a["href"].lstrip("/")
                if href.startswith("serie/") and href not in episode_links:
                    episode_links.append(href)

            if not episode_links:
                self._debug(f"no Addic7ed episode links found for query: {search_query}")
                return results

            wanted_season = str(int(season)) if str(season).isdigit() else str(season)
            wanted_episode = str(int(episode)) if str(episode).isdigit() else str(episode)

            # Prefer URLs whose path contains /season/episode/ after /serie/show/.
            prioritized = []
            for href in episode_links:
                parts = href.split("/")
                score = 0
                if len(parts) >= 4:
                    if parts[2] == wanted_season and parts[3] == wanted_episode:
                        score = 100
                    elif wanted_season in parts and wanted_episode in parts:
                        score = 50
                prioritized.append((score, href))
            prioritized.sort(reverse=True)

            for _score, href in prioritized[:5]:
                page = self.session.get(f"{self.BASE_URL}/{href}", timeout=20, allow_redirects=True)
                if page.status_code != 200:
                    continue
                page_soup = BeautifulSoup(page.text, "html.parser")
                results.extend(self._parse_episode_page(page_soup, language, title=title, season=season, episode=episode))
                if results:
                    break

        except Exception as e:
            self._debug(f"search error: {e}")

        return results[:15]

    def download(self, href, output_path):
        try:
            self._login()
            url = f"{self.BASE_URL}{href}" if not href.startswith("http") else href
            resp = self.session.get(
                url,
                timeout=30,
                allow_redirects=True,
                headers={"Referer": self.BASE_URL},
            )
            if resp.status_code != 200:
                return {"ok": False, "error": f"Download failed: status {resp.status_code}"}

            content = resp.content or b""
            start = content[:500].decode("utf-8", errors="ignore").lower()
            if "<html" in start or "<!doctype" in start or "<body" in start:
                return {"ok": False, "error": "Addic7ed returned HTML instead of a subtitle file. Login, rate-limit, or site layout may be blocking the download."}
            if len(content) < 20:
                return {"ok": False, "error": "Downloaded subtitle was empty or too small"}

            with open(output_path, "wb") as f:
                f.write(content)
            return {"ok": True, "path": output_path, "size": len(content)}
        except Exception as e:
            return {"ok": False, "error": str(e)}



# ── Provider: YIFY Subtitles ────────────────────────────────────────────
class YIFYProvider:
    BASE_URL = "https://yifysubtitles.ch"

    def search(self, title, year=None, imdb_id=None, language="en", **kwargs):
        results = []
        if not imdb_id:
            return results

        try:
            resp = requests.get(
                f"{self.BASE_URL}/movie-imdb/{imdb_id}",
                headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"},
                timeout=15,
            )
            if resp.status_code != 200:
                return results

            from bs4 import BeautifulSoup
            soup = BeautifulSoup(resp.text, "html.parser")

            lang_map = {
                "english": "en", "spanish": "es", "french": "fr",
                "german": "de", "italian": "it", "portuguese": "pt",
                "russian": "ru", "dutch": "nl", "arabic": "ar",
                "chinese": "zh", "japanese": "ja", "korean": "ko",
                "turkish": "tr", "polish": "pl", "swedish": "sv",
                "danish": "da", "finnish": "fi", "norwegian": "no",
                "czech": "cs", "hungarian": "hu", "romanian": "ro",
                "hebrew": "he", "brazilian": "pt", "bengali": "bn",
                "vietnamese": "vi", "indonesian": "id", "thai": "th",
                "ukrainian": "uk", "greek": "el", "bulgarian": "bg",
                "croatian": "hr", "serbian": "sr", "slovak": "sk",
                "slovenian": "sl", "estonian": "et", "latvian": "lv",
                "lithuanian": "lt", "malay": "ms", "tagalog": "tl",
                "farsi": "fa", "persian": "fa", "urdu": "ur", "hindi": "hi",
                "tamil": "ta", "telugu": "te", "punjabi": "pa",
                "albanian": "sq", "macedonian": "mk", "icelandic": "is",
                "catalan": "ca", "basque": "eu", "galician": "gl",
                "welsh": "cy", "irish": "ga",
            }

            for a in soup.find_all("a", href=True):
                href = a.get("href", "")
                if "/subtitles/" not in href:
                    continue

                # First try: language from the row's <span class="sub-lang"> if present
                row = a.find_parent("tr")
                sub_lang = ""
                if row:
                    lang_cell = row.find("span", class_="sub-lang")
                    if lang_cell:
                        cell_text = lang_cell.get_text(strip=True).lower()
                        sub_lang = lang_map.get(cell_text, "")

                # Fallback: detect language from URL slug
                if not sub_lang:
                    parts = href.rstrip("/").split("-")
                    for part in parts:
                        if part.lower() in lang_map:
                            sub_lang = lang_map[part.lower()]
                            break

                # TIGHTENED: if we still can't confirm the language, DROP the row.
                # Previously we fell through to the requested language, which let
                # foreign-language subs masquerade as English.
                if not sub_lang:
                    continue

                # Only keep results matching the requested language
                if language and sub_lang != language:
                    continue

                # Rating
                rating_val = 0
                if row:
                    rating_span = row.find("span", class_="label")
                    if rating_span:
                        try:
                            rating_val = int(rating_span.get_text(strip=True))
                        except (ValueError, TypeError):
                            pass

                # Display name — truncated to keep the UI clean if YIFY's HTML is messy
                display_name = a.get_text(strip=True) or href.split("/")[-1]
                if len(display_name) > 160:
                    display_name = display_name[:157] + "..."

                results.append({
                    "provider": "yify",
                    "provider_name": "YIFY Subtitles",
                    "file_id": href,
                    "filename": display_name,
                    "language": sub_lang,
                    "release": display_name,
                    "download_count": 0,
                    "hearing_impaired": False,
                    "fps": 0,
                    "match_type": "query",
                    "rating": rating_val,
                    "uploader": "",
                    "machine_translated": False,
                })

        except Exception:
            pass

        return results[:15]

    def download(self, href, output_path):
        try:
            if not href.startswith("http"):
                href = f"{self.BASE_URL}{href}"

            # YIFY subtitle pages have a download link
            resp = requests.get(
                href, headers={"User-Agent": "SubSyncarr v3.0"}, timeout=15
            )
            if resp.status_code != 200:
                return {"ok": False, "error": f"Page fetch failed: {resp.status_code}"}

            from bs4 import BeautifulSoup
            soup = BeautifulSoup(resp.text, "html.parser")
            dl_btn = soup.select_one("a.btn-icon.download-subtitle")
            if not dl_btn:
                return {"ok": False, "error": "Download link not found on page"}

            dl_url = f"{self.BASE_URL}{dl_btn['href']}"
            file_resp = requests.get(
                dl_url, headers={"User-Agent": "SubSyncarr v3.0"}, timeout=30
            )

            content = file_resp.content
            # May be a zip
            if content[:2] == b"PK":
                import zipfile, io
                with zipfile.ZipFile(io.BytesIO(content)) as zf:
                    sub_files = [n for n in zf.namelist()
                                 if n.lower().endswith((".srt", ".ass", ".ssa", ".vtt"))]
                    if sub_files:
                        content = zf.read(sub_files[0])

            with open(output_path, "wb") as f:
                f.write(content)
            return {"ok": True, "path": output_path, "size": len(content)}

        except Exception as e:
            return {"ok": False, "error": str(e)}


# ── Provider: Gestdown ──────────────────────────────────────────────────
class GestdownProvider:
    BASE_URL = "https://api.gestdown.info"

    def search(self, title, year=None, language="en",
               season=None, episode=None, **kwargs):
        results = []

        try:
            # Search for the show/movie
            resp = requests.get(
                f"{self.BASE_URL}/shows/search/{requests.utils.quote(title)}",
                timeout=15,
                headers={"Accept": "application/json"},
            )
            if resp.status_code != 200:
                return results

            shows_data = resp.json()
            if not shows_data:
                return results

            # API returns {"shows": [...]}
            show_list = shows_data.get("shows", shows_data) if isinstance(shows_data, dict) else shows_data
            if isinstance(show_list, dict):
                show_list = [show_list]
            if not show_list:
                return results

            # Find best matching show
            show = None
            title_lower = title.lower()
            for s in show_list:
                if s.get("name", "").lower() == title_lower:
                    show = s
                    break
            if not show:
                show = show_list[0]

            show_id = show.get("id", "")
            if not show_id:
                return results

            # Get subtitles for the specific episode or latest
            lang_map = {"en": "english", "es": "spanish", "fr": "french",
                        "de": "german", "it": "italian", "pt": "portuguese",
                        "ru": "russian", "nl": "dutch", "pl": "polish",
                        "sv": "swedish", "tr": "turkish", "ar": "arabic"}
            lang_name = lang_map.get(language, "english")

            if season and episode:
                sub_url = f"{self.BASE_URL}/subtitles/get/{show_id}/{season}/{episode}/{lang_name}"
            else:
                sub_url = f"{self.BASE_URL}/subtitles/get/{show_id}/1/1/{lang_name}"

            sub_resp = requests.get(sub_url, timeout=15,
                                    headers={"Accept": "application/json"})
            if sub_resp.status_code != 200:
                return results

            sub_data = sub_resp.json()
            subtitles = sub_data if isinstance(sub_data, list) else sub_data.get("subtitles", sub_data.get("matchingSubtitles", []))

            for sub in subtitles[:15]:
                sub_id = sub.get("subtitleId", sub.get("id", ""))
                results.append({
                    "provider": "gestdown",
                    "provider_name": "Gestdown",
                    "file_id": str(sub_id),
                    "filename": sub.get("fileName", sub.get("title", title)),
                    "language": language,
                    "release": sub.get("fileName", ""),
                    "download_count": sub.get("downloadCount", 0),
                    "hearing_impaired": sub.get("hearingImpaired", False),
                    "fps": 0,
                    "match_type": "query",
                    "rating": 0,
                    "uploader": sub.get("contributor", ""),
                    "machine_translated": False,
                })

        except Exception:
            pass

        return results

    def download(self, sub_id, output_path):
        try:
            resp = requests.get(
                f"{self.BASE_URL}/subtitles/download/{sub_id}",
                timeout=30,
                headers={"Accept": "application/octet-stream"},
            )
            if resp.status_code != 200:
                return {"ok": False, "error": f"Download failed: {resp.status_code}"}

            content = resp.content
            if content[:2] == b"PK":
                import zipfile, io
                with zipfile.ZipFile(io.BytesIO(content)) as zf:
                    sub_files = [n for n in zf.namelist()
                                 if n.lower().endswith((".srt", ".ass", ".ssa", ".vtt"))]
                    if sub_files:
                        content = zf.read(sub_files[0])

            with open(output_path, "wb") as f:
                f.write(content)
            return {"ok": True, "path": output_path, "size": len(content)}

        except Exception as e:
            return {"ok": False, "error": str(e)}


# ── Main CLI Interface ──────────────────────────────────────────────────
def main():
    if len(sys.argv) < 3:
        print(json.dumps({"error": "Usage: subtitle_search.py <action> <json_args>"}))
        sys.exit(1)

    action = sys.argv[1]
    try:
        args = json.loads(sys.argv[2])
    except json.JSONDecodeError:
        print(json.dumps({"error": "Invalid JSON arguments"}))
        sys.exit(1)

    if action == "search":
        do_search(args)
    elif action == "download":
        do_download(args)
    elif action == "test":
        do_test(args)
    elif action == "preview":
        do_preview(args)
    else:
        print(json.dumps({"error": f"Unknown action: {action}"}))
        sys.exit(1)


def do_test(args):
    """Test a specific provider's connectivity and credentials."""
    provider_name = args.get("provider", "")
    providers_config = args.get("providers", {})

    if provider_name == "opensubtitles":
        config = providers_config.get("opensubtitles", {})
        if not config.get("api_key"):
            print(json.dumps({"ok": False, "error": "API key is required"}))
            return
        provider = OpenSubtitlesProvider(
            api_key=config["api_key"],
            username=config.get("username", ""),
            password=config.get("password", ""),
        )
        # Try login if credentials provided
        if config.get("username") and config.get("password"):
            if provider.login():
                print(json.dumps({
                    "ok": True,
                    "message": "OpenSubtitles.com — authenticated successfully"
                }))
            else:
                print(json.dumps({
                    "ok": False,
                    "error": "Login failed — check username and password"
                }))
        else:
            # Just test API key with a search
            try:
                resp = requests.get(
                    f"{provider.BASE_URL}/subtitles",
                    headers=provider.headers,
                    params={"query": "test", "languages": "en"},
                    timeout=10,
                )
                if resp.status_code == 200:
                    print(json.dumps({
                        "ok": True,
                        "message": "OpenSubtitles.com — API key valid"
                    }))
                elif resp.status_code == 401:
                    print(json.dumps({
                        "ok": False,
                        "error": "Invalid API key"
                    }))
                else:
                    print(json.dumps({
                        "ok": False,
                        "error": f"API returned status {resp.status_code}"
                    }))
            except Exception as e:
                print(json.dumps({"ok": False, "error": str(e)}))

    elif provider_name == "subdl":
        config = providers_config.get("subdl", {})
        if not config.get("api_key"):
            print(json.dumps({"ok": False, "error": "API key is required"}))
            return
        try:
            resp = requests.get(
                SubDLProvider.BASE_URL,
                params={"api_key": config["api_key"], "film_name": "test", "languages": "en"},
                timeout=10,
            )
            if resp.status_code == 200:
                data = resp.json()
                if data.get("status"):
                    print(json.dumps({
                        "ok": True,
                        "message": "SubDL — API key valid, connection successful"
                    }))
                else:
                    print(json.dumps({
                        "ok": False,
                        "error": "SubDL returned error: " + str(data.get("error", "unknown"))
                    }))
            else:
                print(json.dumps({
                    "ok": False,
                    "error": f"SubDL returned status {resp.status_code}"
                }))
        except Exception as e:
            print(json.dumps({"ok": False, "error": str(e)}))

    elif provider_name == "podnapisi":
        try:
            resp = requests.get(
                PodnapisiProvider.BASE_URL,
                params={"keywords": "test", "language": "2", "output_type": "json"},
                headers={"User-Agent": "SubSyncarr v2.0", "Accept": "application/json"},
                timeout=10,
            )
            if resp.status_code == 200:
                print(json.dumps({
                    "ok": True,
                    "message": "Podnapisi — connection successful (no auth required)"
                }))
            else:
                print(json.dumps({
                    "ok": False,
                    "error": f"Podnapisi returned status {resp.status_code} — site may be down"
                }))
        except requests.exceptions.ConnectionError:
            print(json.dumps({
                "ok": False,
                "error": "Podnapisi — server unreachable (site may be temporarily down)"
            }))
        except Exception as e:
            print(json.dumps({"ok": False, "error": str(e)}))

    elif provider_name == "addic7ed":
        config = providers_config.get("addic7ed", {})
        try:
            provider = Addic7edProvider(
                username=config.get("username", ""),
                password=config.get("password", ""),
            )
            # Real functional test, not just HTTP 200 from /search.php.
            # Addic7ed is TV-focused, so verify against a known TV episode that
            # the provider can search, follow the episode page, and parse one or
            # more /original or /updated subtitle download links.
            results = provider.search(
                "Tracker (2024)",
                language="en",
                season=3,
                episode=20,
            )
            if results:
                first = results[0]
                print(json.dumps({
                    "ok": True,
                    "message": f"Addic7ed — functional test passed. Parsed {len(results)} subtitle link(s)."
                }))
            else:
                print(json.dumps({
                    "ok": False,
                    "error": "Addic7ed — search returned no parseable subtitle links. The site may be rate-limiting, blocking, or changed its layout."
                }))
        except requests.exceptions.ConnectionError:
            print(json.dumps({"ok": False, "error": "Addic7ed — server unreachable"}))
        except Exception as e:
            print(json.dumps({"ok": False, "error": str(e)}))

    elif provider_name == "yify":
        try:
            resp = requests.get(
                f"{YIFYProvider.BASE_URL}/",
                headers={"User-Agent": "SubSyncarr v3.0"},
                timeout=10,
            )
            if resp.status_code == 200:
                print(json.dumps({
                    "ok": True,
                    "message": "YIFY Subtitles — connection successful (no auth required)"
                }))
            else:
                print(json.dumps({
                    "ok": False,
                    "error": f"YIFY returned status {resp.status_code}"
                }))
        except requests.exceptions.ConnectionError:
            print(json.dumps({"ok": False, "error": "YIFY — server unreachable"}))
        except Exception as e:
            print(json.dumps({"ok": False, "error": str(e)}))

    elif provider_name == "gestdown":
        try:
            resp = requests.get(
                f"{GestdownProvider.BASE_URL}/shows/search/test",
                headers={"Accept": "application/json"},
                timeout=10,
            )
            if resp.status_code == 200:
                print(json.dumps({
                    "ok": True,
                    "message": "Gestdown — connection successful (no auth required)"
                }))
            else:
                print(json.dumps({
                    "ok": False,
                    "error": f"Gestdown returned status {resp.status_code}"
                }))
        except requests.exceptions.ConnectionError:
            print(json.dumps({"ok": False, "error": "Gestdown — server unreachable"}))
        except Exception as e:
            print(json.dumps({"ok": False, "error": str(e)}))

    else:
        print(json.dumps({"ok": False, "error": f"Unknown provider: {provider_name}"}))


def do_search(args):
    """Search all enabled providers for subtitles."""
    title = args.get("title", "")
    year = args.get("year")
    imdb_id = args.get("imdb_id")
    language = args.get("language", "en")
    video_path = args.get("video_path")
    season = args.get("season")
    episode = args.get("episode")
    providers_config = args.get("providers", {})

    all_results = []

    # OpenSubtitles.com
    os_config = providers_config.get("opensubtitles", {})
    if os_config.get("enabled") and os_config.get("api_key"):
        try:
            provider = OpenSubtitlesProvider(
                api_key=os_config["api_key"],
                username=os_config.get("username", ""),
                password=os_config.get("password", ""),
            )
            results = provider.search(
                title, year, imdb_id, language, video_path, season, episode
            )
            all_results.extend(results)
        except Exception as e:
            pass

    # SubDL
    subdl_config = providers_config.get("subdl", {})
    if subdl_config.get("enabled") and subdl_config.get("api_key"):
        try:
            provider = SubDLProvider(api_key=subdl_config["api_key"])
            results = provider.search(
                title, year, imdb_id, language, season=season, episode=episode
            )
            all_results.extend(results)
        except Exception as e:
            pass

    # Podnapisi
    pod_config = providers_config.get("podnapisi", {})
    if pod_config.get("enabled"):
        try:
            provider = PodnapisiProvider()
            results = provider.search(
                title, year, language, season=season, episode=episode
            )
            all_results.extend(results)
        except Exception as e:
            pass

    # Addic7ed
    add_config = providers_config.get("addic7ed", {})
    if add_config.get("enabled"):
        try:
            provider = Addic7edProvider(
                username=add_config.get("username", ""),
                password=add_config.get("password", ""),
            )
            results = provider.search(
                title, language=language, season=season, episode=episode
            )
            all_results.extend(results)
        except Exception:
            pass

    # YIFY
    yify_config = providers_config.get("yify", {})
    if yify_config.get("enabled"):
        try:
            provider = YIFYProvider()
            results = provider.search(title, year, imdb_id, language)
            all_results.extend(results)
        except Exception:
            pass

    # Gestdown
    gestdown_config = providers_config.get("gestdown", {})
    if gestdown_config.get("enabled"):
        try:
            provider = GestdownProvider()
            results = provider.search(
                title, year, language, season=season, episode=episode
            )
            all_results.extend(results)
        except Exception:
            pass

    # Filter out results that don't match the search title
    if title:
        title_words = [w.lower() for w in title.split() if len(w) > 2]
        # Require most title words to match (e.g. "Breaking Bad" needs both "breaking" AND "bad")
        min_match = len(title_words) if len(title_words) <= 2 else max(2, len(title_words) - 1)
        filtered = []
        for r in all_results:
            parent = (r.get("parent_title", "") or "").lower()
            feat = (r.get("feature_title", "") or "").lower()

            # Prefer structured title fields (OpenSubtitles); fall back to release/filename
            if parent:
                check_text = parent
            elif feat and r.get("season_number"):
                check_text = feat
            else:
                check_text = (r.get("filename", "") + " " + r.get("release", "")).lower()

            matching = sum(1 for w in title_words if w in check_text)
            if title_words and matching < min_match:
                continue
            filtered.append(r)
        all_results = filtered

    # Sort: hash matches first, then by download count
    search_title = title.lower() if title else ""
    all_results.sort(
        key=lambda r: (
            0 if r.get("match_type") == "hash" else 1,
            1 if r.get("machine_translated") else 0,
            -(r.get("download_count", 0)),
        )
    )

    print(json.dumps({
        "ok": True,
        "results": all_results,
        "total": len(all_results),
    }))


def do_preview(args):
    """
    Download a subtitle to a temp file, parse it, return first ~30 dialogue lines.
    Strips .srt cue numbers and timestamps so the user sees actual subtitle TEXT.
    Temp file is deleted before we return.
    """
    import tempfile, os as _os, re as _re

    provider_name = args.get("provider", "")
    file_id = args.get("file_id", "")
    providers_config = args.get("providers", {})
    max_lines = int(args.get("max_lines", 30))

    if not provider_name or not file_id:
        print(json.dumps({"ok": False, "error": "provider and file_id required"}))
        return

    # Write to a temp file we can delete after reading
    tmp = tempfile.NamedTemporaryFile(suffix=".srt", delete=False, dir="/tmp")
    tmp.close()
    tmp_path = tmp.name

    try:
        # Reuse the existing download infrastructure
        result = {"ok": False, "error": "Unknown provider"}
        if provider_name == "opensubtitles":
            cfg = providers_config.get("opensubtitles", {})
            p = OpenSubtitlesProvider(api_key=cfg.get("api_key", ""),
                                       username=cfg.get("username", ""),
                                       password=cfg.get("password", ""))
            result = p.download(file_id, tmp_path)
        elif provider_name == "subdl":
            cfg = providers_config.get("subdl", {})
            p = SubDLProvider(api_key=cfg.get("api_key", ""))
            result = p.download(file_id, tmp_path)
        elif provider_name == "podnapisi":
            result = PodnapisiProvider().download(file_id, tmp_path)
        elif provider_name == "addic7ed":
            cfg = providers_config.get("addic7ed", {})
            p = Addic7edProvider(username=cfg.get("username", ""),
                                  password=cfg.get("password", ""))
            result = p.download(file_id, tmp_path)
        elif provider_name == "yify":
            result = YIFYProvider().download(file_id, tmp_path)
        elif provider_name == "gestdown":
            result = GestdownProvider().download(file_id, tmp_path)

        if not result.get("ok"):
            print(json.dumps({"ok": False, "error": result.get("error", "Download failed")}))
            return

        # Read the .srt, extract dialogue lines only
        try:
            with open(tmp_path, "rb") as f:
                raw = f.read()
            # Detect HTML responses (provider block / Cloudflare challenge / login wall)
            head = raw[:500].decode("utf-8", errors="ignore").lower()
            if "<!doctype" in head or "<html" in head or "cf-mitigated" in head or "just a moment" in head:
                print(json.dumps({
                    "ok": False,
                    "error": "Provider returned an HTML page instead of a subtitle file (likely a Cloudflare challenge or login block). This subtitle cannot be previewed or downloaded right now."
                }))
                return
            # Try UTF-8, fall back to latin-1
            try:
                text = raw.decode("utf-8")
            except UnicodeDecodeError:
                try:
                    text = raw.decode("utf-8-sig")
                except UnicodeDecodeError:
                    text = raw.decode("latin-1", errors="replace")
        except Exception as e:
            print(json.dumps({"ok": False, "error": f"Could not read subtitle file: {e}"}))
            return

        # Parse .srt blocks. Each block: cue_number \n timestamp \n text_lines... \n\n
        # We extract just the text_lines.
        blocks = _re.split(r"\r?\n\r?\n", text)
        dialogue = []
        timestamp_re = _re.compile(r"^\d{1,2}:\d{2}:\d{2}[,.]\d{1,3}\s*-->")
        cue_re = _re.compile(r"^\d+$")
        for block in blocks:
            block_lines = block.strip().split("\n")
            text_lines = []
            for line in block_lines:
                s = line.strip()
                if not s:
                    continue
                if cue_re.match(s):
                    continue
                if timestamp_re.match(s):
                    continue
                # Strip SRT/HTML tags for readability
                s = _re.sub(r"<[^>]+>", "", s)
                s = _re.sub(r"\{[^}]+\}", "", s)
                text_lines.append(s)
            if text_lines:
                dialogue.append(" ".join(text_lines))
            if len(dialogue) >= max_lines:
                break

        if not dialogue:
            print(json.dumps({"ok": False, "error": "Subtitle parsed but no dialogue found (file may be empty or in an unsupported format)"}))
            return

        print(json.dumps({
            "ok": True,
            "lines": dialogue[:max_lines],
            "shown": len(dialogue[:max_lines]),
        }))

    finally:
        try:
            _os.unlink(tmp_path)
        except OSError:
            pass


def do_download(args):
    """Download a subtitle from a specific provider."""
    provider_name = args.get("provider", "")
    file_id = args.get("file_id", "")
    output_path = args.get("output_path", "")
    providers_config = args.get("providers", {})

    if not file_id or not output_path:
        print(json.dumps({"ok": False, "error": "file_id and output_path required"}))
        return

    result = {"ok": False, "error": "Unknown provider"}

    if provider_name == "opensubtitles":
        config = providers_config.get("opensubtitles", {})
        provider = OpenSubtitlesProvider(
            api_key=config.get("api_key", ""),
            username=config.get("username", ""),
            password=config.get("password", ""),
        )
        result = provider.download(file_id, output_path)

    elif provider_name == "subdl":
        config = providers_config.get("subdl", {})
        provider = SubDLProvider(api_key=config.get("api_key", ""))
        result = provider.download(file_id, output_path)

    elif provider_name == "podnapisi":
        provider = PodnapisiProvider()
        result = provider.download(file_id, output_path)

    elif provider_name == "addic7ed":
        config = providers_config.get("addic7ed", {})
        provider = Addic7edProvider(
            username=config.get("username", ""),
            password=config.get("password", ""),
        )
        result = provider.download(file_id, output_path)

    elif provider_name == "yify":
        provider = YIFYProvider()
        result = provider.download(file_id, output_path)

    elif provider_name == "gestdown":
        provider = GestdownProvider()
        result = provider.download(file_id, output_path)

    print(json.dumps(result))


if __name__ == "__main__":
    main()
