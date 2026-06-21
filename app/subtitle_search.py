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
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36",
            "Referer": self.BASE_URL,
            "Accept-Language": "en-US,en;q=0.9",
        })
        self.logged_in = False

    def _login(self):
        if self.logged_in or not self.username or not self.password:
            return
        try:
            self.session.post(f"{self.BASE_URL}/dologin.php", data={
                "username": self.username,
                "password": self.password,
                "Submit": "Log in",
            }, timeout=10)
            self.logged_in = True
        except Exception:
            pass

    def search(self, title, year=None, language="en", season=None, episode=None, **kwargs):
        results = []
        try:
            from bs4 import BeautifulSoup
        except ImportError:
            return results

        self._login()
        search_query = title
        if season and episode:
            search_query = f"{title} {season}x{str(episode).zfill(2)}"

        try:
            resp = self.session.get(
                f"{self.BASE_URL}/search.php",
                params={"search": search_query, "Submit": "Search"},
                timeout=15,
            )
            if resp.status_code != 200:
                return results

            soup = BeautifulSoup(resp.text, "html.parser")

            # Parse search results - look for subtitle download links
            for row in soup.select("tr.epeven, tr.completed"):
                cells = row.find_all("td")
                if len(cells) < 4:
                    continue

                # Addic7ed row: season, episode, title, language, version, completed, HI, corrected, download
                lang_cell = cells[3].get_text(strip=True) if len(cells) > 3 else ""
                lang_map = {"English": "en", "Spanish": "es", "French": "fr",
                            "German": "de", "Italian": "it", "Portuguese": "pt",
                            "Russian": "ru", "Dutch": "nl", "Polish": "pl",
                            "Swedish": "sv", "Danish": "da", "Finnish": "fi",
                            "Norwegian": "no", "Turkish": "tr", "Arabic": "ar",
                            "Hebrew": "he", "Chinese": "zh", "Japanese": "ja",
                            "Korean": "ko", "Czech": "cs", "Hungarian": "hu",
                            "Romanian": "ro"}
                lang_code = lang_map.get(lang_cell, "")
                if language and lang_code and lang_code != language:
                    continue

                # Find download link - look for /original/ or /updated/ in href
                dl_link = None
                for a in row.find_all("a"):
                    href = a.get("href", "")
                    if "/original/" in href or "/updated/" in href or "/finalsource" in href:
                        dl_link = a
                        break

                if not dl_link:
                    continue

                href = dl_link.get("href", "")
                release = cells[4].get_text(strip=True) if len(cells) > 4 else ""
                hi = bool(row.find("img", {"title": lambda t: t and "hearing" in t.lower()})) if row else False

                # Extract season/episode from row
                row_season = cells[0].get_text(strip=True) if len(cells) > 0 else ""
                row_episode = cells[1].get_text(strip=True) if len(cells) > 1 else ""
                row_title = cells[2].get_text(strip=True) if len(cells) > 2 else ""

                # Filter by season/episode if specified
                if season and row_season and str(season) != row_season:
                    continue
                if episode and row_episode and str(episode) != row_episode:
                    continue

                display_name = f"S{row_season.zfill(2)}E{row_episode.zfill(2)} - {row_title}" if row_season and row_episode else release or title

                results.append({
                    "provider": "addic7ed",
                    "provider_name": "Addic7ed",
                    "file_id": href,
                    "filename": display_name,
                    "language": lang_code or language,
                    "release": release,
                    "download_count": 0,
                    "hearing_impaired": hi,
                    "fps": 0,
                    "match_type": "query",
                    "rating": 0,
                    "uploader": "",
                    "machine_translated": False,
                })

        except Exception:
            pass

        return results[:15]

    def download(self, href, output_path):
        try:
            self._login()
            url = f"{self.BASE_URL}{href}" if not href.startswith("http") else href
            resp = self.session.get(url, timeout=30)
            if resp.status_code == 200 and len(resp.content) > 10:
                with open(output_path, "wb") as f:
                    f.write(resp.content)
                return {"ok": True, "path": output_path, "size": len(resp.content)}
            return {"ok": False, "error": f"Download failed: status {resp.status_code}"}
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

            # Find subtitle links in the table
            for a in soup.find_all("a", href=True):
                href = a.get("href", "")
                if "/subtitles/" not in href:
                    continue

                # URL format: /subtitles/movie-name-year-LANGUAGE-uploader-ID
                parts = href.rstrip("/").split("-")

                # Extract language from URL
                sub_lang = ""
                lang_map = {
                    "english": "en", "spanish": "es", "french": "fr",
                    "german": "de", "italian": "it", "portuguese": "pt",
                    "russian": "ru", "dutch": "nl", "arabic": "ar",
                    "chinese": "zh", "japanese": "ja", "korean": "ko",
                    "turkish": "tr", "polish": "pl", "swedish": "sv",
                    "danish": "da", "finnish": "fi", "norwegian": "no",
                    "czech": "cs", "hungarian": "hu", "romanian": "ro",
                    "hebrew": "he", "brazilian": "pt",
                }
                for part in parts:
                    if part.lower() in lang_map:
                        sub_lang = lang_map[part.lower()]
                        break

                if language and sub_lang and sub_lang != language:
                    continue

                # Get the rating from parent row if available
                row = a.find_parent("tr")
                rating_val = 0
                if row:
                    rating_span = row.find("span", class_="label")
                    if rating_span:
                        try:
                            rating_val = int(rating_span.get_text(strip=True))
                        except (ValueError, TypeError):
                            pass

                display_name = a.get_text(strip=True) or href.split("/")[-1]

                results.append({
                    "provider": "yify",
                    "provider_name": "YIFY Subtitles",
                    "file_id": href,
                    "filename": display_name,
                    "language": sub_lang or language,
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
        try:
            resp = requests.get(
                f"{Addic7edProvider.BASE_URL}/search.php",
                params={"search": "test", "Submit": "Search"},
                headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"},
                timeout=10,
            )
            if resp.status_code == 200:
                print(json.dumps({
                    "ok": True,
                    "message": "Addic7ed — connection successful" + (
                        " (login optional)" if not providers_config.get("addic7ed", {}).get("username") else "")
                }))
            else:
                print(json.dumps({
                    "ok": False,
                    "error": f"Addic7ed returned status {resp.status_code}"
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
