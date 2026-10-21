import { describe, expect, test } from 'vitest';
import type { RegionCity } from './regionDepartments';
import {
    departmentSelection,
    groupByDepartment,
    regionCoverage,
    toggleDepartment,
} from './regionDepartments';

const city = (
    id: number,
    department: string,
    regionId: number | null = null,
): RegionCity => ({ id, name: `Commune ${id}`, department, regionId });

const oueme = [city(1, 'Ouémé', 3), city(2, 'Ouémé', 3), city(3, 'Ouémé')];
const plateau = [city(4, 'Plateau', 3), city(5, 'Plateau', 3)];

describe('groupByDepartment', () => {
    test('departments come in French alphabetical order, communes as given', () => {
        const groups = groupByDepartment([
            city(1, 'Zou'),
            city(2, 'Atacora'),
            city(3, 'Ouémé'),
            city(4, 'Atacora'),
        ]);

        expect(groups.map((group) => group.department)).toEqual([
            'Atacora',
            'Ouémé',
            'Zou',
        ]);
        expect(groups[0].cities.map((c) => c.id)).toEqual([2, 4]);
    });
});

describe('departmentSelection', () => {
    test('tells a department held wholly, in part or not at all', () => {
        expect(departmentSelection(oueme, [1, 2, 3])).toBe('all');
        expect(departmentSelection(oueme, [1, 4])).toBe('some');
        expect(departmentSelection(oueme, [4, 5])).toBe('none');
    });
});

describe('toggleDepartment', () => {
    test('a department held in part is completed, without duplicates', () => {
        expect(toggleDepartment(oueme, [4, 1])).toEqual([4, 1, 2, 3]);
    });

    test('a department held wholly is released, the rest kept', () => {
        expect(toggleDepartment(oueme, [4, 1, 2, 3])).toEqual([4]);
    });
});

describe('regionCoverage', () => {
    test('lists only the departments the region holds, flagging a partial one', () => {
        expect(
            regionCoverage([...oueme, ...plateau, city(6, 'Zou', 5)], 3),
        ).toEqual([
            { department: 'Ouémé', held: 2, total: 3 },
            { department: 'Plateau', held: 2, total: 2 },
        ]);
    });
});
