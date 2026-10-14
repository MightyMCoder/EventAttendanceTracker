from lxml import etree


file_name = 'languages/en.xml'
parser = etree.XMLParser(remove_blank_text=False)
tree = etree.parse(file_name, parser)
root = tree.getroot()

new_children = []
buffer = []
seen_names = set()


def sort_key(element):
    return tuple(element.attrib.get('name', '').split('_'))


def flush_buffer():
    if not buffer:
        return
    for element in sorted(buffer, key=sort_key):
        name = element.attrib.get('name')
        if name is not None and name in seen_names:
            print(f'Duplicate string found: {name}, skipping.')
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
tree.write(file_name, encoding='utf-8', xml_declaration=True, pretty_print=True)
print('en.xml successfully sorted and duplicates removed.')
