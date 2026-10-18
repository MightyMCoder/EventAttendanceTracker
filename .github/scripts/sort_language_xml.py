from pathlib import Path

from lxml import etree


def sort_key(element):
    return tuple(element.attrib.get('name', '').split('_'))


def sort_file(file_name: Path) -> None:
    parser = etree.XMLParser(remove_blank_text=False)
    tree = etree.parse(str(file_name), parser)
    root = tree.getroot()

    new_children = []
    buffer = []
    seen_names = set()

    def flush_buffer() -> None:
        if not buffer:
            return
        for element in sorted(buffer, key=sort_key):
            name = element.attrib.get('name')
            if name is not None and name in seen_names:
                print(f'Duplicate string found in {file_name}: {name}, skipping.')
                continue
            if name is not None:
                seen_names.add(name)
            new_children.append(element)
        buffer.clear()

    for element in root.iterchildren():
        if isinstance(element, etree._Comment):
            flush_buffer()
            new_children.append(element)
        elif element.tag == 'string':
            buffer.append(element)
        else:
            flush_buffer()
            new_children.append(element)

    flush_buffer()
    root[:] = new_children
    tree.write(str(file_name), encoding='utf-8', xml_declaration=True, pretty_print=True)
    print(f'{file_name} successfully sorted and duplicates removed.')


for language_file in sorted(Path('languages').glob('*.xml')):
    sort_file(language_file)
