import argparse
import os
import re
import xml.etree.ElementTree as ET
from pathlib import Path


parser = argparse.ArgumentParser()
parser.add_argument('--exclude', default='', help='Comma-separated directories to skip')
args = parser.parse_args()
excluded_directories = {directory.strip() for directory in args.exclude.split(',') if directory.strip()}
dynamic_translation_ids = {
    'PLG_EVENT_ATTENDANCE_TRACKER_RATE_DECIMAL_PLACES_DESC',
    'PLG_EVENT_ATTENDANCE_TRACKER_RATE_RED_THRESHOLD_DESC',
    'PLG_EVENT_ATTENDANCE_TRACKER_RATE_ORANGE_THRESHOLD_DESC',
    'PLG_EVENT_ATTENDANCE_TRACKER_RECORDING_LOCK_DAYS_DESC',
    'PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS_SUCCESS',
    'PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS_WARNING',
    'PLG_EVENT_ATTENDANCE_TRACKER_AUTOMATION_STATUS_DANGER',
}

source_contents = []
for directory_path, _, file_names in os.walk('.'):
    if any(part in excluded_directories for part in directory_path.split(os.sep)):
        continue
    for file_name in file_names:
        if not file_name.endswith(('.php', '.js', '.html', '.tpl')):
            continue
        with open(os.path.join(directory_path, file_name), 'r', errors='ignore') as source_file:
            source_contents.append(source_file.read())


def is_used(key: str) -> bool:
    if key in dynamic_translation_ids:
        return True
    return any(key in source for source in source_contents)


unused_keys = []
for language_file in sorted(Path('languages').glob('*.xml')):
    root = ET.parse(language_file).getroot()
    keys = [
        element.attrib['name']
        for element in root.findall('.//string')
        if re.fullmatch(r'[A-Z0-9_]+', element.attrib['name'])
    ]
    unused_keys.extend((language_file, key) for key in keys if not is_used(key))

if unused_keys:
    for language_file, key in unused_keys:
        print(f'UNUSED: {language_file}: {key}')
    raise SystemExit(1)
